<?php

namespace App\Jobs;

use App\Models\EpsEstimateHistory;
use App\Models\EpsRevisionAlert;
use App\Services\MarketData\MarketCap;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\StockProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * For a single symbol: fetch the latest analyst EPS estimate for the
 * NEXT quarter, compare against the previously stored value, and if the
 * revision percent crosses the configured positive/negative threshold,
 * create an EpsRevisionAlert (idempotent on the unique index) and
 * dispatch SendEpsRevisionAlert.
 *
 * Always updates the eps_estimate_history snapshot for the (symbol,
 * period) pair so subsequent runs compare to the most recent value.
 */
class CheckEpsRevisionForSymbol implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $symbol,
        public ?string $companyName = null,
        public ?string $exchange = null,
        // Provider-reported market cap, used only as a backward-compatible
        // fallback. The canonical value is computed: price × shares_outstanding.
        public ?int $marketCap = null,
        public ?float $price = null,
        public ?int $sharesOutstanding = null,
        public ?int $floatShares = null,
        public ?float $freeFloat = null,
    ) {}

    public function handle(MarketDataProvider $provider, StockProvisioner $stocks): void
    {
        $symbol = strtoupper(trim($this->symbol));
        if ($symbol === '') {
            return;
        }

        $config = config('market_data.revision_scanner');
        $positiveThreshold = (float) ($config['positive_threshold'] ?? 20);
        $negativeThreshold = (float) ($config['negative_threshold'] ?? -20);

        try {
            $rows = $provider->analystEstimates($symbol, 'quarter');
        } catch (Throwable $e) {
            Log::error('eps_revision.fetch_failed', [
                'symbol' => $symbol,
                'msg' => $e->getMessage(),
            ]);

            return;
        }

        if (empty($rows)) {
            return;
        }

        // Choose the NEXT quarter — earliest period whose date is in the future.
        // FMP returns rows for many periods; pick the smallest period >= today.
        $today = CarbonImmutable::today()->toDateString();
        usort($rows, fn ($a, $b) => strcmp((string) ($a['period'] ?? ''), (string) ($b['period'] ?? '')));

        $next = null;
        foreach ($rows as $row) {
            if (! empty($row['period']) && $row['period'] >= $today) {
                $next = $row;
                break;
            }
        }
        // Fallback: latest available row if nothing in the future.
        $next ??= end($rows) ?: null;
        if (! $next || empty($next['period']) || ($next['eps_avg'] ?? null) === null) {
            return;
        }

        $period = (string) $next['period'];
        $latestEstimate = (float) $next['eps_avg'];

        // Provision a Stock row for this symbol so it shows up everywhere.
        try {
            $stocks->findOrCreate($symbol, $this->exchange, $this->companyName);
        } catch (Throwable $e) {
            Log::warning('eps_revision.stock_provision_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
        }

        $history = EpsEstimateHistory::query()
            ->where('source', 'fmp')
            ->where('symbol', $symbol)
            ->whereDate('next_quarter_end_date', $period)
            ->first();

        // First-ever snapshot: store and exit (no previous to compare against).
        if (! $history) {
            EpsEstimateHistory::firstOrCreate(
                [
                    'source' => 'fmp',
                    'symbol' => $symbol,
                    'next_quarter_end_date' => $period,
                ],
                [
                    'eps_estimate' => $latestEstimate,
                    'collected_at' => now(),
                ],
            );

            return;
        }

        $previous = (float) $history->eps_estimate;

        // Skip if denominator is zero/null (per spec).
        if ($previous == 0.0) {
            // Still refresh the snapshot so we can compare next time.
            $history->update([
                'eps_estimate' => $latestEstimate,
                'collected_at' => now(),
            ]);

            return;
        }

        $revisionPct = (($latestEstimate - $previous) / abs($previous)) * 100;

        $direction = null;
        if ($revisionPct >= $positiveThreshold) {
            $direction = EpsRevisionAlert::DIRECTION_POSITIVE;
        } elseif ($revisionPct <= $negativeThreshold) {
            $direction = EpsRevisionAlert::DIRECTION_NEGATIVE;
        }

        if ($direction !== null) {
            $alert = EpsRevisionAlert::firstOrCreate(
                [
                    'source' => 'fmp',
                    'symbol' => $symbol,
                    'next_quarter_end_date' => $period,
                    'alert_type' => 'eps_revision',
                    'direction' => $direction,
                ],
                [
                    'company_name' => $this->companyName,
                    'exchange' => $this->exchange,
                    'previous_estimate' => $previous,
                    'latest_estimate' => $latestEstimate,
                    'revision_percent' => round($revisionPct, 4),
                    // market_cap is the canonical computed value
                    // (price × shares_outstanding) with $this->marketCap as a
                    // fallback for symbols where shares are not yet captured.
                    'market_cap' => MarketCap::compute(
                        $this->price,
                        $this->sharesOutstanding,
                        $this->marketCap,
                    ),
                    'price' => $this->price,
                    'shares_outstanding' => $this->sharesOutstanding,
                    'float_shares' => $this->floatShares,
                    'free_float' => $this->freeFloat,
                    'status' => 'new',
                    'detected_at' => now(),
                ],
            );

            if ($alert->wasRecentlyCreated) {
                SendEpsRevisionAlert::dispatch($alert->id);
            }
        }

        // Always refresh the stored snapshot so future runs compare to the
        // most recently observed estimate (otherwise a one-time raise would
        // re-alert on every run).
        $history->update([
            'eps_estimate' => $latestEstimate,
            'collected_at' => now(),
        ]);
    }
}
