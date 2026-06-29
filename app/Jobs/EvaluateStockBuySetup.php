<?php

namespace App\Jobs;

use App\Enums\MoatLevel;
use App\Models\StockBuySetupAlert;
use App\Models\StockDailyBar;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\StockBuySetupScanner;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use App\Services\Stocks\StockProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * For a single symbol: incrementally fetch & persist daily OHLCV bars,
 * run the StockBuySetupScanner over the resulting series, and if the
 * spike + consolidation gates pass with heartbeat_score >= min, create
 * an idempotent StockBuySetupAlert and propagate to opted-in users'
 * "Setup" watchlists.
 *
 * Wrapped in try/catch like ScanEarningsSurprises — one bad symbol must
 * never abort the rest of the scan.
 */
class EvaluateStockBuySetup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public string $symbol,
        public ?string $companyName = null,
        public ?string $exchange = null,
        public ?int $marketCap = null,
    ) {}

    public function handle(
        MarketDataProvider $provider,
        StockBuySetupScanner $scanner,
        StockBuySetupScorer $scorer,
        StockFundamentalsAnalyzer $fundamentals,
        StockProvisioner $stocks,
    ): void {
        $symbol = strtoupper(trim($this->symbol));
        if ($symbol === '') {
            return;
        }

        try {
            $config = config('market_data.buy_setup_scanner');
            $lookback = (int) ($config['history_lookback_days'] ?? 504);
            $minScore = (int) ($config['min_setup_score'] ?? $config['min_heartbeat_score'] ?? 50);

            $bars = $this->loadBars($provider, $symbol, $lookback);
            if (count($bars) < 252) {
                return;
            }

            $benchmarks = (array) ($config['benchmark_symbols'] ?? ['SPY', 'IWM']);
            $benchmarkBars = $this->loadBenchmark($provider, $benchmarks);
            $fundamentalMetrics = $this->loadFundamentalMetrics($provider, $fundamentals, $symbol);

            $results = $scanner->evaluateAll($bars, $benchmarkBars, array_merge([
                'symbol' => $symbol,
                'company_name' => $this->companyName,
                'exchange' => $this->exchange,
                'market_cap' => $this->marketCap,
            ], $fundamentalMetrics));

            foreach ($results as $result) {
                $score = $scorer->score($result);
                $result->heartbeatScore = $score;
                $result->setupScore = $score;
                if ($result->setupScore < $minScore) {
                    continue;
                }

                $spikeDate = $result->spikeDate->toDateString();

                $alert = StockBuySetupAlert::firstOrCreate(
                    [
                        'source' => 'fmp',
                        'symbol' => $symbol,
                        'setup_type' => $result->setupType,
                        'spike_date' => $spikeDate,
                    ],
                    [
                        'setup_score' => $result->setupScore,
                        'company_name' => $result->companyName,
                        'exchange' => $result->exchange,
                        'market_cap' => $result->marketCap,
                        'market_cap_category' => $result->marketCapCategory,
                        'spike_volume' => $result->spikeVolume,
                        'prior_52w_max_volume' => $result->prior52wMaxVolume,
                        'max_104w_volume' => $result->max104wVolume,
                        'is_52w_high_volume' => $result->is52wHighVolume,
                        'is_104w_high_volume' => $result->is104wHighVolume,
                        'days_since_previous_comparable_spike' => $result->daysSincePreviousComparableSpike,
                        'base_start_date' => $result->baseStart->toDateString(),
                        'base_end_date' => $result->baseEnd->toDateString(),
                        'base_duration_days' => $result->baseDurationDays,
                        'base_high' => $result->baseHigh,
                        'base_low' => $result->baseLow,
                        'range_compression_pct' => $result->rangeCompressionPct,
                        'atr_contraction_ratio' => $result->atrContractionRatio,
                        'volume_dry_up_score' => $result->volumeDryUpScore,
                        'slope' => $result->slope,
                        'distance_to_breakout_pct' => $result->distanceToBreakoutPct,
                        'ma_alignment' => $result->maAlignment,
                        'relative_strength_score' => $result->relativeStrengthScore,
                        'earnings_acceleration' => $result->earningsAcceleration,
                        'sales_acceleration' => $result->salesAcceleration,
                        'quarterly_eps_growth_pct' => $result->quarterlyEpsGrowthPct,
                        'quarterly_revenue_growth_pct' => $result->quarterlyRevenueGrowthPct,
                        'annual_eps_growth_pct' => $result->annualEpsGrowthPct,
                        'roe_pct' => $result->roePct,
                        'profit_margin_pct' => $result->profitMarginPct,
                        'spike_relative_volume' => $result->spikeRelativeVolume,
                        'eps_growth_sequence' => $result->epsGrowthSequence,
                        'revenue_growth_sequence' => $result->revenueGrowthSequence,
                        'heartbeat_score' => $result->heartbeatScore,
                        'reason_summary' => $result->reasonSummary,
                        'status' => 'new',
                        'detected_at' => now(),
                    ],
                );

                // Provision a Stock row & propagate to opted-in users' Setup watchlist.
                try {
                    $stock = $stocks->findOrCreate($symbol, $this->exchange, $this->companyName);

                    User::query()
                        ->where('notify_stock_buy_setup', true)
                        ->chunkById(100, function ($users) use ($stock, $alert) {
                            foreach ($users as $user) {
                                $watchlist = Watchlist::firstOrCreate(
                                    ['user_id' => $user->id, 'name' => 'Setup'],
                                    [
                                        'description' => 'Auto-created from Stock Buy Setup scanner.',
                                        'is_default' => false,
                                    ],
                                );

                                WatchlistItem::firstOrCreate(
                                    [
                                        'watchlist_id' => $watchlist->id,
                                        'stock_id' => $stock->id,
                                    ],
                                    [
                                        'currency' => $stock->currency->value,
                                        'moat_level' => MoatLevel::Medium->value,
                                        'notes' => 'Buy setup ['.$alert->setup_type.'] ('.$alert->setup_score.'/100): '.$alert->reason_summary,
                                    ],
                                );
                            }
                        });
                } catch (Throwable $e) {
                    Log::warning('buy_setup.watchlist_provision_failed', [
                        'symbol' => $symbol,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($alert->wasRecentlyCreated) {
                    SendStockBuySetupAlert::dispatch($alert->id);
                }
            }
        } catch (Throwable $e) {
            Log::error('buy_setup.evaluate_failed', [
                'symbol' => $symbol,
                'msg' => $e->getMessage(),
            ]);
        }
    }


    /**
     * @return array<string, mixed>
     */
    private function loadFundamentalMetrics(
        MarketDataProvider $provider,
        StockFundamentalsAnalyzer $fundamentals,
        string $symbol,
    ): array {
        try {
            $income = $provider->quarterlyIncomeStatements($symbol, 8);
            if (empty($income)) {
                return [];
            }

            $balance = $provider->quarterlyBalanceSheets($symbol, 8);

            return $fundamentals->analyze($income, $balance);
        } catch (Throwable $e) {
            Log::warning('buy_setup.fundamentals_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Load up to $lookback trading days of bars for $symbol: read what's
     * persisted, only fetch the gap from FMP, upsert new rows, then return
     * the merged ascending series in the scanner's row shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadBars(MarketDataProvider $provider, string $symbol, int $lookback): array
    {
        $to = CarbonImmutable::today();
        // Calendar window must be wider than trading days; FMP returns
        // weekdays only, so ~1.5x lookback handles weekends/holidays.
        $from = $to->subDays((int) ceil($lookback * 1.5));

        $existing = StockDailyBar::query()
            ->where('symbol', $symbol)
            ->whereBetween('bar_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('bar_date')
            ->get();

        $lastStored = $existing->isNotEmpty()
            ? CarbonImmutable::parse($existing->last()->bar_date)
            : null;

        // Incremental fetch: only days since the most recent stored bar (or full window on first run).
        $fetchFrom = $lastStored?->addDay() ?? $from;
        $fresh = [];
        if ($fetchFrom <= $to) {
            $fresh = $provider->historicalDailyBars($symbol, $fetchFrom, $to);
            foreach ($fresh as $row) {
                if (empty($row['date'])) {
                    continue;
                }
                StockDailyBar::updateOrCreate(
                    ['symbol' => $symbol, 'bar_date' => $row['date']],
                    [
                        'open' => $row['open'] ?? null,
                        'high' => $row['high'] ?? null,
                        'low' => $row['low'] ?? null,
                        'close' => $row['close'] ?? null,
                        'adj_close' => $row['adj_close'] ?? null,
                        'volume' => $row['volume'] ?? null,
                    ],
                );
            }
        }

        // Reload merged series.
        return StockDailyBar::query()
            ->where('symbol', $symbol)
            ->whereBetween('bar_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('bar_date')
            ->get()
            ->map(fn (StockDailyBar $b) => [
                'date' => $b->bar_date->toDateString(),
                'open' => (float) $b->open,
                'high' => (float) $b->high,
                'low' => (float) $b->low,
                'close' => (float) $b->close,
                'adj_close' => $b->adj_close !== null ? (float) $b->adj_close : null,
                'volume' => (int) $b->volume,
            ])
            ->all();
    }

    /**
     * Fetch the first available benchmark series (e.g. SPY, then IWM)
     * cached for the duration of the run. Returns [] when none are
     * available on the active FMP plan.
     */
    private function loadBenchmark(MarketDataProvider $provider, array $symbols): array
    {
        foreach ($symbols as $sym) {
            $sym = strtoupper(trim((string) $sym));
            if ($sym === '') {
                continue;
            }
            $bars = Cache::remember(
                "buy_setup.benchmark.$sym",
                now()->addHours(12),
                fn () => $provider->historicalDailyBars(
                    $sym,
                    CarbonImmutable::today()->subDays(400),
                    CarbonImmutable::today(),
                ),
            );
            if (! empty($bars)) {
                return $bars;
            }
        }

        return [];
    }
}
