<?php

namespace App\Jobs;

use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Services\Earnings\EarningsSurpriseScorer;
use App\Services\MarketData\MarketCap;
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

        // Enrich missing exchange / shares / company name via profile,
        // and missing price / volume via quote. The provider's `market_cap`
        // field is captured as a fallback only — the canonical value is
        // computed below as price × shares_outstanding.
        $providerMarketCap = null;
        if (! $event->exchange || $event->shares_outstanding === null || ! $event->company_name) {
            if ($profile = $provider->profile($event->symbol)) {
                $event->exchange = $event->exchange ?: ($profile['exchange'] ?? null);
                $event->company_name = $event->company_name ?: ($profile['company_name'] ?? null);

                $event->shares_outstanding = $event->shares_outstanding
                    ?? ($profile['shares_outstanding'] ?? null);
                $event->float_shares = $event->float_shares
                    ?? ($profile['float_shares'] ?? null);
                $event->free_float = $event->free_float
                    ?? ($profile['free_float'] ?? null);

                $providerMarketCap = $providerMarketCap ?? ($profile['market_cap'] ?? null);
            }
        }

        if ($quote = $provider->quote($event->symbol)) {
            $event->price = $event->price ?: ($quote['price'] ?? null);
            $event->volume = $event->volume ?: ($quote['volume'] ?? null);
            $event->avg_volume = $event->avg_volume ?: ($quote['avg_volume'] ?? null);

            // FMP's composite quote() also surfaces company_name / exchange
            // from /profile internally — use them when the explicit
            // profile() call above didn't return (e.g. /profile gated on
            // the current FMP plan, or already cached as null).
            if (! $event->company_name && ! empty($quote['company_name'])) {
                $event->company_name = $quote['company_name'];
            }
            if (! $event->exchange && ! empty($quote['exchange'])) {
                $event->exchange = $quote['exchange'];
            }

            $event->shares_outstanding = $event->shares_outstanding
                ?? ($quote['shares_outstanding'] ?? null);
            $event->float_shares = $event->float_shares
                ?? ($quote['float_shares'] ?? null);
            $event->free_float = $event->free_float
                ?? ($quote['free_float'] ?? null);

            $providerMarketCap = $providerMarketCap ?? ($quote['market_cap'] ?? null);

            if ($event->volume && $event->avg_volume && $event->avg_volume > 0) {
                $event->relative_volume = round($event->volume / $event->avg_volume, 4);
            }
        }

        // Canonical market_cap = price × shares_outstanding, with the
        // provider-reported value used only as a backward-compatible
        // fallback when shares are still missing.
        $computedMarketCap = MarketCap::compute(
            $event->price,
            $event->shares_outstanding,
            $event->getRawOriginal('market_cap') ?: $providerMarketCap,
        );
        if ($computedMarketCap !== null) {
            $event->setAttribute('market_cap', $computedMarketCap);
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
