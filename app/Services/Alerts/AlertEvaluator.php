<?php

namespace App\Services\Alerts;

use App\Enums\AlertRuleType;
use App\Models\Stock;
use App\Models\WatchlistAlertEvent;
use App\Models\WatchlistAlertRule;
use App\Models\WatchlistItem;
use App\Notifications\WatchlistAlertMail;
use Illuminate\Support\Facades\Notification;

/**
 * Evaluates active alert rules for a Stock and produces WatchlistAlertEvent
 * records. Notifications are dispatched per event via the WatchlistAlertMail
 * notification.
 *
 * Triggering logic is intentionally simple and side-effect-free outside of
 * the events it creates. External market data does not flow through this
 * service — it only inspects the persisted Stock columns updated by
 * `market-watch:update-quotes`.
 */
class AlertEvaluator
{
    /**
     * Evaluate every active rule attached to watchlist items for this stock.
     *
     * @return list<WatchlistAlertEvent>
     */
    public function evaluateStock(Stock $stock): array
    {
        $items = WatchlistItem::query()
            ->with(['watchlist.user', 'alertRules'])
            ->where('stock_id', $stock->id)
            ->get();

        $events = [];

        foreach ($items as $item) {
            foreach ($item->alertRules->where('is_active', true) as $rule) {
                $event = $this->evaluateRule($rule, $item, $stock);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    private function evaluateRule(WatchlistAlertRule $rule, WatchlistItem $item, Stock $stock): ?WatchlistAlertEvent
    {
        $triggered = false;
        $message = null;

        switch ($rule->type) {
            case AlertRuleType::PriceAbove:
                if ($stock->last_price && $rule->target_price
                    && $stock->last_price->greaterThanOrEqualTo($rule->target_price)) {
                    $triggered = true;
                    $message = "Price {$stock->last_price->format()} crossed above target {$rule->target_price->format()}.";
                }
                break;

            case AlertRuleType::PriceBelow:
                if ($stock->last_price && $rule->target_price
                    && $stock->last_price->lessThanOrEqualTo($rule->target_price)) {
                    $triggered = true;
                    $message = "Price {$stock->last_price->format()} dropped below target {$rule->target_price->format()}.";
                }
                break;

            case AlertRuleType::PercentChange:
                $threshold = (int) ($rule->parameters['threshold_bps'] ?? 0); // basis points * 100
                if ($stock->daily_change_percent !== null && $threshold > 0
                    && abs($stock->daily_change_percent) >= $threshold) {
                    $triggered = true;
                    $message = 'Daily change exceeded threshold.';
                }
                break;

            case AlertRuleType::VolumeSpike:
                $multiplier = (float) ($rule->parameters['multiplier'] ?? 0);
                $baseline = (int) ($rule->parameters['baseline_volume'] ?? 0);
                if ($multiplier > 0 && $baseline > 0
                    && $stock->volume !== null && $stock->volume >= (int) ($baseline * $multiplier)) {
                    $triggered = true;
                    $message = "Volume {$stock->volume} exceeded {$multiplier}x baseline {$baseline}.";
                }
                break;

            case AlertRuleType::Breakout52Week:
                $high = (int) ($rule->parameters['high_52w_minor'] ?? 0);
                if ($high > 0 && $stock->last_price && $stock->last_price->minor >= $high) {
                    $triggered = true;
                    $message = '52-week high breakout.';
                }
                break;

            case AlertRuleType::ManualReview:
                // Manual review rules never auto-trigger; they exist as flags
                // for human follow-up and are surfaced in the UI.
                return null;
        }

        if (! $triggered) {
            return null;
        }

        $event = WatchlistAlertEvent::create([
            'watchlist_alert_rule_id' => $rule->id,
            'watchlist_item_id' => $item->id,
            'severity' => $rule->severity->value,
            'triggered_at' => now(),
            'currency' => $stock->currency->value,
            'observed_price' => $stock->last_price,
            'context' => [
                'symbol' => $stock->symbol,
                'exchange' => $stock->exchange->value,
                'rule_type' => $rule->type->value,
                'rule_parameters' => $rule->parameters,
            ],
            'message' => $message,
        ]);

        $rule->forceFill(['last_triggered_at' => now()])->save();

        $this->dispatchNotifications($event, $rule, $item, $stock);

        return $event;
    }

    private function dispatchNotifications(
        WatchlistAlertEvent $event,
        WatchlistAlertRule $rule,
        WatchlistItem $item,
        Stock $stock,
    ): void {
        $user = $item->watchlist?->user;
        if (! $user) {
            return;
        }

        Notification::send($user, new WatchlistAlertMail($event, $rule, $item, $stock));

        $event->recordNotification('mail');
    }
}
