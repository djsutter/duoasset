<?php

namespace Tests\Feature\MarketData;

use App\Models\CompanyFinancial;
use App\Models\MarketDataPayload;
use App\Services\MarketData\FinancialStorageConfigService;
use App\Services\MarketData\MarketDataProvider;
use App\Services\MarketData\PersistentMarketDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PersistentMarketDataProviderFinancialStorageTest extends TestCase
{
    use RefreshDatabase;

    protected FinancialStorageConfigService $storageConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageConfig = app(FinancialStorageConfigService::class);
    }

    public function test_when_enabled_it_stores_financial_statements_in_database(): void
    {
        $mockUpstream = Mockery::mock(MarketDataProvider::class);
        $mockUpstream->shouldReceive('quarterlyIncomeStatements')
            ->once()
            ->with('AAPL', 8)
            ->andReturn([
                [
                    'date' => '2026-06-30',
                    'revenue' => 85000000000,
                    'net_income' => 20000000000,
                    'eps' => 1.40,
                    'period' => 'Q2',
                ],
            ]);

        $provider = new PersistentMarketDataProvider($mockUpstream, $this->storageConfig);

        $this->storageConfig->enable();
        $this->assertTrue($provider->isFinancialStorageEnabled());

        $results = $provider->quarterlyIncomeStatements('AAPL', 8);

        $this->assertCount(1, $results);
        $this->assertEquals('2026-06-30', $results[0]['date']);
        $this->assertEquals(85000000000, $results[0]['revenue']);

        // Persisted in database
        $this->assertDatabaseHas('company_financials', [
            'symbol' => 'AAPL',
            'statement_type' => CompanyFinancial::TYPE_INCOME,
            'fiscal_date' => '2026-06-30',
        ]);
        $this->assertDatabaseHas('market_data_payloads', [
            'symbol' => 'AAPL',
            'data_type' => 'income',
        ]);
    }

    public function test_when_disabled_it_fetches_upstream_without_writing_to_database(): void
    {
        $mockUpstream = Mockery::mock(MarketDataProvider::class);
        $mockUpstream->shouldReceive('quarterlyIncomeStatements')
            ->once()
            ->with('MSFT', 8)
            ->andReturn([
                [
                    'date' => '2026-06-30',
                    'revenue' => 64000000000,
                    'net_income' => 22000000000,
                    'eps' => 2.95,
                    'period' => 'Q2',
                ],
            ]);

        $mockUpstream->shouldReceive('quarterlyBalanceSheets')
            ->once()
            ->with('MSFT', 8)
            ->andReturn([
                [
                    'date' => '2026-06-30',
                    'stockholders_equity' => 250000000000,
                    'period' => 'Q2',
                ],
            ]);

        $mockUpstream->shouldReceive('quarterlyCashFlowStatements')
            ->once()
            ->with('MSFT', 8)
            ->andReturn([
                [
                    'date' => '2026-06-30',
                    'free_cash_flow' => 23000000000,
                    'period' => 'Q2',
                ],
            ]);

        $provider = new PersistentMarketDataProvider($mockUpstream, $this->storageConfig);

        $this->storageConfig->disable();
        $this->assertFalse($provider->isFinancialStorageEnabled());

        $income = $provider->quarterlyIncomeStatements('MSFT', 8);
        $balance = $provider->quarterlyBalanceSheets('MSFT', 8);
        $cashFlow = $provider->quarterlyCashFlowStatements('MSFT', 8);

        $this->assertCount(1, $income);
        $this->assertEquals('2026-06-30', $income[0]['date']);
        $this->assertEquals(64000000000, $income[0]['revenue']);

        $this->assertCount(1, $balance);
        $this->assertEquals('2026-06-30', $balance[0]['date']);
        $this->assertEquals(250000000000, $balance[0]['stockholders_equity']);

        $this->assertCount(1, $cashFlow);
        $this->assertEquals('2026-06-30', $cashFlow[0]['date']);
        $this->assertEquals(23000000000, $cashFlow[0]['free_cash_flow']);

        // Zero records inserted into database
        $this->assertDatabaseMissing('company_financials', ['symbol' => 'MSFT']);
        $this->assertDatabaseMissing('market_data_payloads', ['symbol' => 'MSFT']);
    }

    public function test_when_disabled_existing_records_are_preserved_non_destructively(): void
    {
        // Seed an existing record in DB
        CompanyFinancial::create([
            'symbol' => 'NVDA',
            'statement_type' => CompanyFinancial::TYPE_INCOME,
            'fiscal_date' => '2026-04-30',
            'period' => 'Q1',
            'revenue' => 26000000000,
            'net_income' => 14000000000,
            'eps' => 0.60,
            'retrieved_at' => now(),
        ]);

        MarketDataPayload::create([
            'symbol' => 'NVDA',
            'data_type' => 'income',
            'period' => 'limit_8',
            'payload' => [['date' => '2026-04-30', 'revenue' => 26000000000]],
            'retrieved_at' => now(),
        ]);

        $mockUpstream = Mockery::mock(MarketDataProvider::class);
        $mockUpstream->shouldReceive('quarterlyIncomeStatements')
            ->once()
            ->with('NVDA', 8)
            ->andReturn([
                [
                    'date' => '2026-07-31',
                    'revenue' => 30000000000,
                    'net_income' => 16000000000,
                    'eps' => 0.68,
                    'period' => 'Q2',
                ],
            ]);

        $provider = new PersistentMarketDataProvider($mockUpstream, $this->storageConfig);

        $this->storageConfig->disable();

        $results = $provider->quarterlyIncomeStatements('NVDA', 8);

        $this->assertCount(1, $results);
        $this->assertEquals('2026-07-31', $results[0]['date']);

        // The pre-existing 2026-04-30 record was NOT deleted or overwritten!
        $this->assertDatabaseHas('company_financials', [
            'symbol' => 'NVDA',
            'fiscal_date' => '2026-04-30',
            'revenue' => 26000000000,
        ]);

        // The new 2026-07-31 record was NOT saved to DB
        $this->assertDatabaseMissing('company_financials', [
            'symbol' => 'NVDA',
            'fiscal_date' => '2026-07-31',
        ]);
    }
}
