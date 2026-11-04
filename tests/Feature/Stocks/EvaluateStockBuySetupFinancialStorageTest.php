<?php

namespace Tests\Feature\Stocks;

use App\Jobs\EvaluateStockBuySetup;
use App\Models\CompanyFinancial;
use App\Services\MarketData\FinancialStorageConfigService;
use App\Services\MarketData\MarketDataProvider;
use App\Services\MarketData\PersistentMarketDataProvider;
use App\Services\Stocks\IgnitionExtensionCalculator;
use App\Services\Stocks\StockBuySetupLiquidityPenalty;
use App\Services\Stocks\StockBuySetupScanner;
use App\Services\Stocks\StockBuySetupScorer;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use App\Services\Stocks\StockProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EvaluateStockBuySetupFinancialStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoring_run_respects_storage_setting_when_enabled_vs_disabled(): void
    {
        $storageConfig = app(FinancialStorageConfigService::class);

        $baseDate = CarbonImmutable::parse('2025-09-01');
        $bars = [];
        for ($i = 0; $i < 260; $i++) {
            $bars[] = [
                'date' => $baseDate->addDays($i)->toDateString(),
                'open' => 10.0,
                'high' => 10.5,
                'low' => 9.5,
                'close' => 10.0,
                'adj_close' => 10.0,
                'volume' => 100000,
            ];
        }

        $mockUpstream = Mockery::mock(MarketDataProvider::class);
        $mockUpstream->shouldReceive('profile')->andReturn([
            'company_name' => 'Test Corp',
            'exchange' => 'NASDAQ',
            'price' => 10.0,
            'shares_outstanding' => 100000000,
            'market_cap' => 1000000000,
        ]);
        $mockUpstream->shouldReceive('historicalDailyBars')->andReturn($bars);
        $mockUpstream->shouldReceive('quarterlyIncomeStatements')->andReturn([
            [
                'date' => '2026-06-30',
                'revenue' => 100000000,
                'net_income' => 20000000,
                'eps' => 0.50,
                'period' => 'Q2',
            ],
        ]);
        $mockUpstream->shouldReceive('quarterlyBalanceSheets')->andReturn([]);
        $mockUpstream->shouldReceive('quarterlyCashFlowStatements')->andReturn([]);

        $provider = new PersistentMarketDataProvider($mockUpstream, $storageConfig);

        // 1. Run scoring with storage ENABLED
        $storageConfig->enable();
        $this->assertTrue($provider->isFinancialStorageEnabled());

        $job1 = new EvaluateStockBuySetup('AAPL', 'Test Corp', 'NASDAQ', 1000000000, 10.0);
        $result1 = $job1->handle(
            $provider,
            app(StockBuySetupScanner::class),
            app(StockBuySetupScorer::class),
            app(StockBuySetupLiquidityPenalty::class),
            app(StockFundamentalsAnalyzer::class),
            app(StockProvisioner::class),
            app(IgnitionExtensionCalculator::class),
        );

        $this->assertIsArray($result1);
        // Financials were stored in DB for AAPL
        $this->assertDatabaseHas('company_financials', [
            'symbol' => 'AAPL',
            'statement_type' => CompanyFinancial::TYPE_INCOME,
        ]);

        // 2. Disable financial storage application-wide (as done by Admin UI)
        $storageConfig->disable();
        $this->assertFalse($provider->isFinancialStorageEnabled());

        // 3. Run scoring with storage DISABLED for MSFT
        $job2 = new EvaluateStockBuySetup('MSFT', 'Test Corp', 'NASDAQ', 1000000000, 10.0);
        $result2 = $job2->handle(
            $provider,
            app(StockBuySetupScanner::class),
            app(StockBuySetupScorer::class),
            app(StockBuySetupLiquidityPenalty::class),
            app(StockFundamentalsAnalyzer::class),
            app(StockProvisioner::class),
            app(IgnitionExtensionCalculator::class),
        );

        $this->assertIsArray($result2);

        // Pre-existing AAPL records are preserved (non-destructive)
        $this->assertDatabaseHas('company_financials', [
            'symbol' => 'AAPL',
            'statement_type' => CompanyFinancial::TYPE_INCOME,
        ]);

        // MSFT records were NOT written to database because storage is disabled
        $this->assertDatabaseMissing('company_financials', [
            'symbol' => 'MSFT',
        ]);
    }
}
