<?php

namespace App\Jobs;

use App\Models\EarningsEvent;
use App\Services\Earnings\EarningsSurpriseScorer;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Enrich a stored earnings event with profile/quote data and create
 * an EarningsAlert + dispatch SendEarningsAlert if it qualifies.
 */
class EnrichEarningsEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $earningsEventId) {}

    public function handle(MarketDataProvider $provider, EarningsSurpriseScorer $scorer): void
    {
        $event = EarningsEvent::find($this->earningsEventId);
        if (! $event) {
            return;
        }

        $config = config('market_data.earnings_scanner');
        $minMcap = (int) ($config['min_market_cap'] ?? 100_000_000);
        $minPct = (float) ($config['min_eps_surprise_percent'] ?? 90);
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

        // Qualifying gates.
        if ($event->eps_surprise_percent === null || (float) $event->eps_surprise_percent < $minPct) {
            return;
        }
        if (! $event->market_cap || (int) $event->market_cap < $minMcap) {
            return;
        }
        if ($event->exchange && ! in_array($event->exchange, $exchanges, true)) {
            return;
        }

        $score = $scorer->score($event);

        // Create the alert idempotently (unique index on event_id+alert_type).
        $alert = $event->alert()->firstOrCreate(
            ['alert_type' => 'eps_surprise'],
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
