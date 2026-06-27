<?php

namespace App\Jobs;

use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Services\Earnings\EarningsSurpriseScorer;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\StockProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enrich a stored earnings event with profile/quote data and create
 * EarningsAlert rows (one per qualifying direction) — then dispatch
 * SendEarningsAlert for any newly-created alerts.
 *
 * Bidirectional gates:
 *   surprise_percent >= positive_threshold → direction = positive (Beat)
 *   surprise_percent <= negative_threshold → direction = negative (Miss)
 */
class EnrichEarningsEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $earningsEventId) {}

    public function handle(MarketDataProvider $provider, EarningsSurpriseScorer $scorer, StockProvisioner $stocks): void
    {
        $event = EarningsEvent::find($this->earningsEventId);
        if (! $event) {
            return;
        }

        $config = config('market_data.earnings_scanner');
        $minMcap = (int) ($config['min_market_cap'] ?? 100_000_000);
        $positiveThreshold = (float) ($config['positive_threshold']
            ?? $config['min_eps_surprise_percent']
            ?? 90);
        $negativeThreshold = (float) ($config['negative_threshold'] ?? -30);
        $exchanges = (array) ($config['exchanges'] ?? []);

        // Enrich missing exchange/market_cap via profile, missing price/volume via quote.
        if (! $event->exchange || ! $event->market_cap || ! $event->company_name) {
            if ($profile = $provider->profile($event->symbol)) {
                $event->exchange = $event->exchange ?: ($profile['exchange'] ?? null);
                $event->market_cap = $event->market_cap ?: ($profile['market_cap'] ?? null);
                $event->company_name = $event->company_name ?: ($profile['company_name'] ?? null);
            }
        }

        if ($quote = $provider->quote($event->symbol)) {
            $event->price = $event->price ?: ($quote['price'] ?? null);
            $event->volume = $event->volume ?: ($quote['volume'] ?? null);
            $event->avg_volume = $event->avg_volume ?: ($quote['avg_volume'] ?? null);
            $event->market_cap = $event->market_cap ?: ($quote['market_cap'] ?? null);

            if ($event->volume && $event->avg_volume && $event->avg_volume > 0) {
                $event->relative_volume = round($event->volume / $event->avg_volume, 4);
            }
        }

        // Recompute EPS surprise percent if missing but we have both sides.
        if ($event->eps_surprise_percent === null) {
            $pct = EarningsSurpriseScorer::calculateSurprisePercent(
                $event->eps_actual !== null ? (float) $event->eps_actual : null,
                $event->eps_estimated !== null ? (float) $event->eps_estimated : null,
            );
            if ($pct !== null) {
                $event->eps_surprise_percent = round($pct, 4);
                if ($event->eps_actual !== null && $event->eps_estimated !== null) {
                    $event->eps_surprise = round((float) $event->eps_actual - (float) $event->eps_estimated, 4);
                }
            }
        }

        $event->save();

        // Ensure a basic Stock row exists for any symbol surfaced by the scanner,
        // regardless of whether the event qualifies for an alert.
        try {
            $stocks->findOrCreate($event->symbol, $event->exchange, $event->company_name);
        } catch (Throwable $e) {
            Log::warning('earnings.stock_provision_failed', [
                'symbol' => $event->symbol,
                'error' => $e->getMessage(),
            ]);
        }

        // Common gates that apply to BOTH directions.
        if ($event->eps_surprise_percent === null) {
            return;
        }
        if (! $event->market_cap || (int) $event->market_cap < $minMcap) {
            return;
        }
        if ($event->exchange && ! in_array($event->exchange, $exchanges, true)) {
            return;
        }

        $surprisePct = (float) $event->eps_surprise_percent;
        $direction = null;
        if ($surprisePct >= $positiveThreshold) {
            $direction = EarningsAlert::DIRECTION_POSITIVE;
        } elseif ($surprisePct <= $negativeThreshold) {
            $direction = EarningsAlert::DIRECTION_NEGATIVE;
        }

        if ($direction === null) {
            return; // Between thresholds — no alert.
        }

        $score = $scorer->score($event);

        // Idempotent on (event_id, alert_type, direction) unique index.
        $alert = $event->alerts()->firstOrCreate(
            ['alert_type' => 'eps_surprise', 'direction' => $direction],
            [
                'symbol' => $event->symbol,
                'score' => $score,
                'status' => 'new',
                'message' => null,
            ],
        );

        if ($alert->wasRecentlyCreated) {
            SendEarningsAlert::dispatch($alert->id);
        }
    }
}
