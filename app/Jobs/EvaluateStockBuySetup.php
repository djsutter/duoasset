<?php

namespace App\Jobs;

use App\Enums\MoatLevel;
use App\Models\StockBuySetupAlert;
use App\Models\StockDailyBar;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupLiquidityPenalty;
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
        public ?float $price = null,
        public ?int $sharesOutstanding = null,
        public ?int $floatShares = null,
        public ?float $freeFloat = null,
    ) {}

    public function handle(
        MarketDataProvider $provider,
        StockBuySetupScanner $scanner,
        StockBuySetupScorer $scorer,
        StockBuySetupLiquidityPenalty $liquidityPenalty,
        StockFundamentalsAnalyzer $fundamentals,
        StockProvisioner $stocks,
    ): array {
        $startedAt = microtime(true);
        $debug = [
            'symbol' => strtoupper(trim($this->symbol)),
            'status' => 'started',
            'reason' => null,
            'bars' => 0,
            'fetched_bars' => 0,
            'existing_bars' => 0,
            'benchmark_bars' => 0,
            'fundamentals_loaded' => false,
            'matches' => [],
            'elapsed_ms' => 0,
        ];
        $symbol = strtoupper(trim($this->symbol));
        if ($symbol === '') {
            $debug['status'] = 'skipped';
            $debug['reason'] = 'empty symbol';

            return $debug;
        }

        try {
            $configService = app(BuySetupConfigService::class);
            $lookback = $configService->getHistoryLookbackDays();
            $notifyMinScore = $configService->getNotifyMinSetupScore();

            $profile = $this->loadProfile($provider, $symbol);
            $companyName = $this->companyName ?: ($profile['company_name'] ?? null);
            $exchange = $this->exchange ?: ($profile['exchange'] ?? null);
            $price = $this->price ?? $this->nullableFloat($profile['price'] ?? null);
            $sharesOutstanding = $this->sharesOutstanding ?? $this->nullableInt($profile['shares_outstanding'] ?? null);
            $floatShares = $this->floatShares ?? $this->nullableInt($profile['float_shares'] ?? null);
            $freeFloat = $this->freeFloat ?? $this->nullableFloat($profile['free_float'] ?? null);
            $marketCap = \App\Services\MarketData\MarketCap::compute($price, $sharesOutstanding, $this->marketCap ?? ($profile['market_cap'] ?? null));

            $barStats = [];
            $bars = $this->loadBars($provider, $symbol, $lookback, $barStats);
            $debug['bars'] = count($bars);
            $debug['fetched_bars'] = (int) ($barStats['fetched_bars'] ?? 0);
            $debug['existing_bars'] = (int) ($barStats['existing_bars'] ?? 0);
            if (count($bars) < 252) {
                $debug['status'] = 'rejected';
                $debug['reason'] = 'insufficient history ('.count($bars).' < 252 bars)';
                $debug['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

                return $debug;
            }

            $benchmarks = $configService->getBenchmarkSymbols();
            $benchmarkBars = $this->loadBenchmark($provider, $benchmarks);
            $debug['benchmark_bars'] = count($benchmarkBars);
            $fundamentalMetrics = $this->loadFundamentalMetrics($provider, $fundamentals, $symbol);
            $debug['fundamentals_loaded'] = ! empty($fundamentalMetrics);

            $results = $scanner->evaluateAll($bars, $benchmarkBars, array_merge([
                'symbol' => $symbol,
                'company_name' => $companyName,
                'exchange' => $exchange,
                'market_cap' => $marketCap,
                'price' => $price,
                'shares_outstanding' => $sharesOutstanding,
                'float_shares' => $floatShares,
                'free_float' => $freeFloat,
            ], $fundamentalMetrics));

            if (empty($results)) {
                $debug['status'] = 'rejected';
                $debug['reason'] = $scanner->lastRejectionReason() ?? 'no enabled setup detector matched';
                $debug['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

                return $debug;
            }

            foreach ($results as $result) {
                $breakdown = $scorer->breakdown(
                    $result,
                    $result->setupType,
                    $this->nullableFloat($fundamentalMetrics['prior_year_revenue'] ?? null),
                );
                $scoreMeta = $scorer->scoreMetaFromBreakdown($breakdown);
                $rawScore = $scoreMeta['normalized'];
                $liquidity = $liquidityPenalty->apply(
                    $rawScore,
                    $result->marketCapCategory,
                    $result->floatShares,
                    $result->sharesOutstanding,
                    $bars,
                    $result->setupType,
                );
                $score = (int) $liquidity['adjusted_score'];

                // Growth Synergy Bonus: a small, configurable bonus added on
                // top of the normal setup score (disabled by default per
                // setup type). Reuses the already-calculated normalized
                // scores above; never recalculated here. See
                // StockBuySetupScorer::growthSynergyBonus().
                $growthSynergyBonus = $scorer->growthSynergyBonus($result, $result->setupType);
                $score = min(100, $score + $growthSynergyBonus['points']);

                $result->rawSetupScore = $rawScore;
                $result->heartbeatScore = $rawScore;
                $result->setupScore = $score;
                $result->avgDailyVolume = $liquidity['average_volume'];
                $result->liquidityTurnoverPct = $liquidity['turnover_pct'];
                $result->liquidityPenaltyPct = (float) $liquidity['penalty_pct'];
                $result->liquidityPenaltyPoints = (int) $liquidity['penalty_points'];

                $spikeDate = $result->spikeDate->toDateString();

                $alert = StockBuySetupAlert::firstOrNew(
                    [
                        'source' => 'fmp',
                        'symbol' => $symbol,
                        'setup_type' => $result->setupType,
                        'spike_date' => $spikeDate,
                    ],
                );
                $wasRecentlyCreated = ! $alert->exists;
                $alert->fill([
                    'setup_score' => $result->setupScore,
                    'raw_setup_score' => $result->rawSetupScore,
                    'company_name' => $result->companyName,
                    'exchange' => $result->exchange,
                    'market_cap' => $result->marketCap,
                    'market_cap_category' => $result->marketCapCategory,
                    'price' => $result->price,
                    'shares_outstanding' => $result->sharesOutstanding,
                    'float_shares' => $result->floatShares,
                    'free_float' => $result->freeFloat,
                    'avg_daily_volume' => $result->avgDailyVolume,
                    'liquidity_turnover_pct' => $result->liquidityTurnoverPct,
                    'liquidity_penalty_pct' => $result->liquidityPenaltyPct,
                    'liquidity_penalty_points' => $result->liquidityPenaltyPoints,
                    'spike_volume' => $result->spikeVolume,
                    'prior_52w_max_volume' => $result->prior52wMaxVolume,
                    'max_104w_volume' => $result->max104wVolume,
                    'is_52w_high_volume' => $result->is52wHighVolume,
                    'is_104w_high_volume' => $result->is104wHighVolume,
                    'days_since_previous_comparable_spike' => $result->daysSincePreviousComparableSpike,
                    'spike_age_bars' => $result->spikeAgeBars,
                    'spike_rarity_points' => $result->spikeRarityPoints,
                    'spike_rarity_description' => $result->spikeRarityDescription,
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
                    'operating_margin_expansion_bps' => $result->operatingMarginExpansionBps,
                    'current_ttm_operating_margin' => $result->currentTtmOperatingMargin,
                    'prior_ttm_operating_margin' => $result->priorTtmOperatingMargin,
                    'fcf_margin_expansion_bps' => $result->fcfMarginExpansionBps,
                    'current_ttm_fcf_margin' => $result->currentTtmFcfMargin,
                    'prior_ttm_fcf_margin' => $result->priorTtmFcfMargin,
                    'heartbeat_score' => $result->heartbeatScore,
                    'reason_summary' => $result->reasonSummary,
                ]);
                if ($wasRecentlyCreated) {
                    $alert->status = 'new';
                    $alert->detected_at = now();
                }
                $alert->save();

                $shouldNotify = $result->setupScore >= $notifyMinScore;

                // Provision a Stock row & propagate to opted-in users' Setup watchlist
                // only when the result clears the notification threshold. Lower-scoring
                // detector matches are still saved for review and UI filtering.
                if ($shouldNotify) {
                    try {
                        $stock = $stocks->findOrCreate($symbol, $exchange, $companyName);

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
                }

                if ($shouldNotify && $wasRecentlyCreated) {
                    SendStockBuySetupAlert::dispatch($alert->id);
                }

                $debug['matches'][] = [
                    'setup_type' => $result->setupType,
                    'status' => $wasRecentlyCreated ? 'created' : 'existing',
                    'setup_score' => $result->setupScore,
                    'raw_setup_score' => $result->rawSetupScore,
                    'heartbeat_score' => $result->heartbeatScore,
                    'raw_score' => $scoreMeta['raw'],
                    'max_score' => $scoreMeta['max'],
                    'notify_min_score' => $notifyMinScore,
                    'notification_eligible' => $shouldNotify,
                    'avg_daily_volume' => $result->avgDailyVolume,
                    'liquidity_turnover_pct' => $result->liquidityTurnoverPct,
                    'liquidity_penalty_pct' => $result->liquidityPenaltyPct,
                    'liquidity_penalty_points' => $result->liquidityPenaltyPoints,
                    'growth_synergy_bonus' => $growthSynergyBonus,
                    'score_breakdown' => $breakdown,
                    'spike_date' => $spikeDate,
                    'spike_age_bars' => $result->spikeAgeBars,
                    'spike_rarity_points' => $result->spikeRarityPoints,
                    'base_days' => $result->baseDurationDays,
                    'range_pct' => $result->rangeCompressionPct,
                    'atr_ratio' => $result->atrContractionRatio,
                    'distance_to_breakout_pct' => $result->distanceToBreakoutPct,
                    'relative_strength' => $result->relativeStrengthScore,
                    'prior_year_revenue' => $fundamentalMetrics['prior_year_revenue'] ?? null,
                    'reason' => $result->reasonSummary,
                ];
            }
            if (empty($debug['matches'])) {
                $debug['status'] = 'rejected';
                $debug['reason'] = 'no detector match was saved';
            } else {
                $debug['status'] = 'matched';
                $debug['reason'] = null;
            }
            $debug['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            return $debug;
        } catch (Throwable $e) {
            Log::error('buy_setup.evaluate_failed', [
                'symbol' => $symbol,
                'msg' => $e->getMessage(),
            ]);

            $debug['status'] = 'error';
            $debug['reason'] = $e->getMessage();
            $debug['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

            return $debug;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProfile(MarketDataProvider $provider, string $symbol): array
    {
        try {
            $profile = $provider->profile($symbol);

            return is_array($profile) ? $profile : [];
        } catch (Throwable $e) {
            Log::warning('buy_setup.profile_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
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
            $cashFlow = $provider->quarterlyCashFlowStatements($symbol, 8);
            $metrics = $fundamentals->analyze($income, $balance, $cashFlow);

            // FMP supplies absolute revenue on each quarterly income statement,
            // not a dedicated prior_year_revenue field. Derive the comparable
            // prior-year quarter here for sales-acceleration denominator damping.
            $metrics['prior_year_revenue'] = $this->priorYearRevenue($income);

            return $metrics;
        } catch (Throwable $e) {
            Log::warning('buy_setup.fundamentals_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Return revenue from the comparable quarter one year before the most
     * recent quarterly income statement.
     *
     * Prefer an exact fiscal-year/period match when FMP provides those fields.
     * Fall back to the fifth row after sorting newest-first, which is the same
     * quarter one year earlier for a normal quarterly series.
     *
     * @param  array<int, array<string, mixed>>  $income
     */
    private function priorYearRevenue(array $income): ?float
    {
        $rows = array_values(array_filter(
            $income,
            fn (array $row) => isset($row['date'])
                && array_key_exists('revenue', $row)
                && is_numeric($row['revenue']),
        ));

        if (count($rows) < 5) {
            return null;
        }

        usort(
            $rows,
            fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']),
        );

        $latest = $rows[0];
        $latestPeriod = $latest['period'] ?? null;
        $latestFiscalYear = isset($latest['fiscalYear']) && is_numeric($latest['fiscalYear'])
            ? (int) $latest['fiscalYear']
            : null;

        if ($latestPeriod !== null && $latestFiscalYear !== null) {
            foreach (array_slice($rows, 1) as $row) {
                $rowFiscalYear = isset($row['fiscalYear']) && is_numeric($row['fiscalYear'])
                    ? (int) $row['fiscalYear']
                    : null;

                if (
                    ($row['period'] ?? null) === $latestPeriod
                    && $rowFiscalYear === $latestFiscalYear - 1
                ) {
                    return (float) $row['revenue'];
                }
            }
        }

        return (float) $rows[4]['revenue'];
    }

    /**
     * Load up to $lookback trading days of bars for $symbol: read what's
     * persisted, only fetch the gap from FMP, upsert new rows, then return
     * the merged ascending series in the scanner's row shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadBars(MarketDataProvider $provider, string $symbol, int $lookback, array &$stats = []): array
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
        $stats['existing_bars'] = $existing->count();

        $lastStored = $existing->isNotEmpty()
            ? CarbonImmutable::parse($existing->last()->bar_date)
            : null;

        // Incremental fetch: only days since the most recent stored bar (or full window on first run).
        $fetchFrom = $lastStored?->addDay() ?? $from;
        $fresh = [];
        if ($fetchFrom <= $to) {
            $fresh = $provider->historicalDailyBars($symbol, $fetchFrom, $to);
            $stats['fetched_bars'] = count($fresh);
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

        $stats['fetched_bars'] = $stats['fetched_bars'] ?? 0;

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
