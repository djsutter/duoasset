<?php

use App\Jobs\EvaluateStockBuySetup;
use App\Jobs\PrepareScannerRunDigests;
use App\Models\CompanyFinancial;
use App\Models\NotificationDigestDelivery;
use App\Models\StockBuySetupAlert;
use App\Models\StockBuySetupRun;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Notifications\SetupScoringDigestNotification;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupLiquidityPenalty;
use App\Services\Stocks\StockBuySetupScanner;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use App\Services\Stocks\StockProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function buildBookValueTestBars(): array
{
    $baseDate = CarbonImmutable::parse('2025-09-01');
    $bars = [];
    for ($i = 0; $i < 250; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays($i)->toDateString(),
            'open' => 10.0,
            'high' => 10.2,
            'low' => 9.8,
            'close' => 10.0,
            'adj_close' => 10.0,
            'volume' => $i >= 220 ? 50_000 : 100_000,
        ];
    }

    // Spike day
    $bars[] = [
        'date' => $baseDate->addDays(250)->toDateString(),
        'open' => 10.0,
        'high' => 11.2,
        'low' => 9.9,
        'close' => 11.0,
        'adj_close' => 11.0,
        'volume' => 500_000,
    ];

    for ($i = 1; $i <= 5; $i++) {
        $bars[] = [
            'date' => $baseDate->addDays(250 + $i)->toDateString(),
            'open' => 11.0,
            'high' => 11.2,
            'low' => 10.8,
            'close' => 11.0,
            'adj_close' => 11.0,
            'volume' => 100_000,
        ];
    }

    return $bars;
}

test('EvaluateStockBuySetup calculates and persists book value per share and price-to-book ratio from FMP balance sheet data', function () {
    $bars = buildBookValueTestBars();

    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('profile')->with('VALU')->andReturn([
        'company_name' => 'Value Breakthrough Corp',
        'exchange' => 'NASDAQ',
        'price' => 11.00,
        'shares_outstanding' => 10_000_000,
        'float_shares' => 8_000_000,
        'free_float' => 80.0,
        'market_cap' => 110_000_000,
        'currency' => 'USD',
    ]);
    $mockProvider->shouldReceive('historicalDailyBars')->andReturn($bars);
    $mockProvider->shouldReceive('quarterlyIncomeStatements')->andReturn([
        [
            'date' => '2026-06-30',
            'revenue' => 25_000_000,
            'net_income' => 3_000_000,
            'eps' => 0.30,
            'operating_income' => 4_000_000,
            'fiscal_year' => 2026,
            'period' => 'Q2',
            'reported_currency' => 'USD',
        ],
    ]);
    $mockProvider->shouldReceive('quarterlyBalanceSheets')->andReturn([
        [
            'date' => '2026-06-30',
            'stockholders_equity' => 100_000_000, // $10.00 book value per share -> P/B = 11.00 / 10.00 = 1.10
            'fiscal_year' => 2026,
            'period' => 'Q2',
            'reported_currency' => 'USD',
        ],
    ]);
    $mockProvider->shouldReceive('quarterlyCashFlowStatements')->andReturn([]);

    $job = new EvaluateStockBuySetup('VALU', 'Value Breakthrough Corp', 'NASDAQ', 110_000_000, 11.00, 10_000_000, 8_000_000, 80.0);
    $result = $job->handle(
        $mockProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
    );

    expect($result['status'])->toBe('matched');

    $alert = StockBuySetupAlert::where('symbol', 'VALU')->first();
    expect($alert)->not->toBeNull()
        ->and((float) $alert->book_value_per_share)->toEqualWithDelta(10.00, 0.001)
        ->and((float) $alert->price_to_book_ratio)->toEqualWithDelta(1.10, 0.001)
        ->and((float) $alert->stockholders_equity)->toEqualWithDelta(100_000_000.0, 1.0)
        ->and($alert->reason_summary)->toContain('Book value P/B 1.10 (8.47/10)');
});

test('historical baseline recalculation is idempotent, suppresses artificial improvement/drop notifications, and preserves future genuine notifications', function () {
    Notification::fake();

    $user = User::factory()->create(['email_setup_scoring_digest' => true]);
    $watchlist = Watchlist::create([
        'user_id' => $user->id,
        'name' => 'Setup Watchlist',
        'is_default' => true,
    ]);
    $stock = (new StockProvisioner(null))->findOrCreate('BVBASE', 'NASDAQ', 'Baseline Value Corp');
    WatchlistItem::create([
        'watchlist_id' => $watchlist->id,
        'stock_id' => $stock->id,
        'currency' => 'USD',
        'moat_level' => \App\Enums\MoatLevel::Medium->value,
    ]);

    // Store balance sheet in company_financials ($12.50 book value per share on 10M shares)
    CompanyFinancial::create([
        'symbol' => 'BVBASE',
        'statement_type' => CompanyFinancial::TYPE_BALANCE_SHEET,
        'fiscal_date' => '2026-06-30',
        'period' => 'Q2',
        'fiscal_year' => 2026,
        'stockholders_equity' => 125_000_000,
        'reported_currency' => 'USD',
        'retrieved_at' => now(),
    ]);

    $run1 = StockBuySetupRun::create([
        'started_at' => now()->subMinutes(10),
        'completed_at' => now()->subMinutes(9),
        'status' => 'completed',
        'symbols_considered' => 1,
        'scored_count' => 1,
        'matched_count' => 1,
    ]);

    // Create a legacy alert (pre-Book-Value baseline score = 60)
    $alert = StockBuySetupAlert::create([
        'source' => 'fmp',
        'scan_run_id' => $run1->id,
        'first_scan_run_id' => $run1->id,
        'symbol' => 'BVBASE',
        'company_name' => 'Baseline Value Corp',
        'exchange' => 'NASDAQ',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 60,
        'previous_setup_score' => 60,
        'score_delta' => 0,
        'raw_setup_score' => 60,
        'heartbeat_score' => 60,
        'price' => 10.00, // P/B = 10.00 / 12.50 = 0.80 -> full 10/10 Book Value points
        'shares_outstanding' => 10_000_000,
        'spike_date' => '2026-10-15',
        'spike_rarity_points' => 7,
        'base_duration_days' => 90,
        'range_compression_pct' => 8.0,
        'atr_contraction_ratio' => 0.55,
        'volume_dry_up_score' => 0.35,
        'distance_to_breakout_pct' => 1.0,
        'ma_alignment' => '50>150>200, price>50',
        'relative_strength_score' => 15.0,
        'status' => 'sent',
        'detected_at' => now()->subDay(),
    ]);

    $scorer = app(StockBuySetupScorer::class);

    // 1. Run historical baseline recalculation
    $updatedCount = $scorer->recalculateHistoricalBaseline();
    expect($updatedCount)->toBe(1);

    $alert->refresh();
    $newBaselineScore = $alert->setup_score;
    expect($newBaselineScore)->toBeGreaterThan(60)
        ->and((float) $alert->book_value_per_share)->toEqualWithDelta(12.50, 0.001)
        ->and((float) $alert->price_to_book_ratio)->toEqualWithDelta(0.80, 0.001)
        ->and($alert->previous_setup_score)->toBe($newBaselineScore)
        ->and($alert->score_delta)->toBe(0);

    // 2. Re-running baseline recalculation is idempotent
    $scorer->recalculateHistoricalBaseline();
    $alert->refresh();
    expect($alert->setup_score)->toBe($newBaselineScore)
        ->and($alert->previous_setup_score)->toBe($newBaselineScore)
        ->and($alert->score_delta)->toBe(0);

    // 3. Digest builder for Run #1 must NOT send any artificial improvement/drop notification
    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_SETUP_SCORING, $run1->id);
    Notification::assertNotSentTo($user, SetupScoringDigestNotification::class);

    // 4. Subsequent genuine score improvement on Run #2 MUST trigger a normal digest notification
    $run2 = StockBuySetupRun::create([
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
        'status' => 'completed',
        'symbols_considered' => 1,
        'scored_count' => 1,
        'matched_count' => 1,
    ]);

    $alert->update([
        'scan_run_id' => $run2->id,
        'previous_setup_score' => $newBaselineScore,
        'setup_score' => min(100, $newBaselineScore + 5),
        'score_delta' => min(100, $newBaselineScore + 5) - $newBaselineScore,
    ]);

    PrepareScannerRunDigests::dispatchSync(NotificationDigestDelivery::CATEGORY_SETUP_SCORING, $run2->id);
    Notification::assertSentToTimes($user, SetupScoringDigestNotification::class, 1);
});

test('EvaluateStockBuySetup derives shares from market_cap / price when FMP profile omits sharesOutstanding and rejects foreign currency balance sheets', function () {
    $bars = buildBookValueTestBars();

    // Case 1: FMP /stable/profile returns market_cap & price but shares_outstanding = null (real FMP /stable behavior)
    $mockProvider = Mockery::mock(MarketDataProvider::class);
    $mockProvider->shouldReceive('profile')->with('CITI')->andReturn([
        'company_name' => 'Citigroup Inc.',
        'exchange' => 'NYSE',
        'price' => 11.00,
        'shares_outstanding' => null, // Omitted by FMP /stable/profile
        'float_shares' => null,
        'free_float' => null,
        'market_cap' => 110_000_000, // Implies 10,000,000 shares at $11.00
        'currency' => null, // Omitted by screener; inferred as USD from NYSE
    ]);
    $mockProvider->shouldReceive('historicalDailyBars')->andReturn($bars);
    $mockProvider->shouldReceive('quarterlyIncomeStatements')->with('CITI', 8)->andReturn([
        [
            'date' => '2026-06-30',
            'revenue' => 25_000_000,
            'net_income' => 3_000_000,
            'eps' => 0.30,
            'operating_income' => 4_000_000,
            'fiscal_year' => 2026,
            'period' => 'Q2',
            'reported_currency' => 'USD',
        ],
    ]);
    $mockProvider->shouldReceive('quarterlyBalanceSheets')->with('CITI', 8)->andReturn([
        [
            'date' => '2026-06-30',
            'stockholders_equity' => 100_000_000, // BVPS = $10.00 -> P/B = 1.10
            'fiscal_year' => 2026,
            'period' => 'Q2',
            'reported_currency' => 'USD',
        ],
    ]);
    $mockProvider->shouldReceive('quarterlyCashFlowStatements')->andReturn([]);

    $job = new EvaluateStockBuySetup('CITI', 'Citigroup Inc.', 'NYSE', 110_000_000, 11.00, null, null, null);
    $result = $job->handle(
        $mockProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
    );

    expect($result['status'])->toBe('matched');
    $citiAlert = StockBuySetupAlert::where('symbol', 'CITI')->first();
    expect($citiAlert)->not->toBeNull()
        ->and((int) $citiAlert->shares_outstanding)->toBe(10_000_000)
        ->and((float) $citiAlert->book_value_per_share)->toEqualWithDelta(10.00, 0.001)
        ->and((float) $citiAlert->price_to_book_ratio)->toEqualWithDelta(1.10, 0.001)
        ->and($citiAlert->reason_summary)->toContain('Book value P/B 1.10 (8.47/10)');

    // Case 2: Foreign ADR reporting in ARS / KRW where currency was missing from CompanyProfile
    // Must be scored as N/A (null P/B), NEVER P/B 0.00 with 10/10 points
    $mockProvider->shouldReceive('profile')->with('CEPU')->andReturn([
        'company_name' => 'Central Puerto S.A.',
        'exchange' => 'NYSE',
        'price' => 11.00,
        'shares_outstanding' => null,
        'float_shares' => null,
        'free_float' => null,
        'market_cap' => 1_863_987_697,
        'currency' => null,
    ]);
    $mockProvider->shouldReceive('quarterlyIncomeStatements')->with('CEPU', 8)->andReturn([
        [
            'date' => '2026-06-30',
            'revenue' => 2_599_768_000_000,
            'net_income' => 126_492_000_000,
            'eps' => 746.50,
            'operating_income' => 180_000_000_000,
            'fiscal_year' => null,
            'period' => 'quarter',
            'reported_currency' => null,
            'raw' => ['reportedCurrency' => 'ARS', 'fiscalYear' => 2026, 'period' => 'Q2'],
        ],
    ]);
    $mockProvider->shouldReceive('quarterlyBalanceSheets')->with('CEPU', 8)->andReturn([
        [
            'date' => '2026-06-30',
            'stockholders_equity' => 9_198_016_000_000, // 9.2T ARS
            'fiscal_year' => null,
            'period' => 'quarter',
            'reported_currency' => null,
            'raw' => ['reportedCurrency' => 'ARS', 'fiscalYear' => 2026, 'period' => 'Q2'],
        ],
    ]);

    $cepuJob = new EvaluateStockBuySetup('CEPU', 'Central Puerto S.A.', 'NYSE', 1_863_987_697, 11.00, null, null, null);
    $cepuJob->handle(
        $mockProvider,
        app(StockBuySetupScanner::class),
        app(StockBuySetupScorer::class),
        app(StockBuySetupLiquidityPenalty::class),
        app(StockFundamentalsAnalyzer::class),
        app(StockProvisioner::class),
    );

    $cepuAlert = StockBuySetupAlert::where('symbol', 'CEPU')->first();
    expect($cepuAlert)->not->toBeNull()
        ->and($cepuAlert->book_value_per_share)->toBeNull()
        ->and($cepuAlert->price_to_book_ratio)->toBeNull()
        ->and($cepuAlert->reason_summary)->not->toContain('Book value P/B');
});

test('recalculateHistoricalBaseline repairs corrupted historical P/B 0.00 records and cleans up reason_summary', function () {
    // Seed a corrupted historical alert that previously received price_to_book_ratio = 0.0000 and 10/10 points
    // due to an unflagged foreign-currency balance sheet in company_financials.
    \App\Models\CompanyProfile::create([
        'symbol' => 'CORRUPT',
        'company_name' => 'Corrupt PB Corp',
        'exchange' => 'NYSE',
        'currency' => null, // Missing currency in company_profiles
        'price' => 25.00,
        'market_cap' => 2_500_000_000,
        'shares_outstanding' => null, // Missing shares_outstanding in company_profiles
    ]);

    CompanyFinancial::create([
        'symbol' => 'CORRUPT',
        'statement_type' => CompanyFinancial::TYPE_BALANCE_SHEET,
        'fiscal_date' => '2026-06-30',
        'period' => 'quarter',
        'fiscal_year' => null,
        'stockholders_equity' => 8_500_000_000_000, // 8.5T KRW
        'reported_currency' => null, // Missing top-level reported_currency
        'raw_metrics' => [
            'reportedCurrency' => 'KRW',
            'fiscalYear' => 2026,
            'period' => 'Q2',
        ],
        'retrieved_at' => now(),
    ]);

    $corruptAlert = StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'CORRUPT',
        'company_name' => 'Corrupt PB Corp',
        'exchange' => 'NYSE',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 85,
        'previous_setup_score' => 85,
        'score_delta' => 0,
        'raw_setup_score' => 85,
        'heartbeat_score' => 85,
        'price' => 25.00,
        'market_cap' => 2_500_000_000,
        'shares_outstanding' => 100_000_000,
        'book_value_per_share' => 85000.0000,
        'price_to_book_ratio' => 0.0000, // Truncated to 0.0000 in DECIMAL(16,4)
        'stockholders_equity' => 8_500_000_000_000,
        'spike_date' => '2026-10-15',
        'spike_rarity_points' => 7,
        'base_duration_days' => 90,
        'range_compression_pct' => 8.0,
        'atr_contraction_ratio' => 0.55,
        'volume_dry_up_score' => 0.35,
        'distance_to_breakout_pct' => 1.0,
        'ma_alignment' => '50>150>200, price>50',
        'relative_strength_score' => 15.0,
        'reason_summary' => "Technical:\n52-wk high volume spike.\n\nFundamentals:\nBook value P/B 0.00 (10/10).",
        'status' => 'new',
        'detected_at' => now(),
    ]);

    $scorer = app(StockBuySetupScorer::class);
    $scorer->recalculateHistoricalBaseline();

    $corruptAlert->refresh();
    $breakdown = $scorer->breakdown($corruptAlert);

    expect($corruptAlert->book_value_per_share)->toBeNull()
        ->and($corruptAlert->price_to_book_ratio)->toBeNull()
        ->and($breakdown['book_value']['available'])->toBeFalse()
        ->and($breakdown['book_value']['points'])->toBe(0)
        ->and($breakdown['book_value']['max'])->toBe(0)
        ->and($corruptAlert->reason_summary)->not->toContain('Book value P/B')
        ->and($corruptAlert->score_delta)->toBe(0);
});
