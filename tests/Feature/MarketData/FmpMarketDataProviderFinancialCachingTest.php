<?php

namespace Tests\Feature\MarketData;

use App\Services\MarketData\FinancialStorageConfigService;
use App\Services\MarketData\FmpMarketDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FmpMarketDataProviderFinancialCachingTest extends TestCase
{
    use RefreshDatabase;

    public function test_when_caching_is_enabled_it_uses_cache_remember(): void
    {
        $storageConfig = app(FinancialStorageConfigService::class);
        $storageConfig->enable();

        Http::fake([
            '*/stable/income-statement*' => Http::response([
                [
                    'date' => '2026-06-30',
                    'revenue' => 100000000,
                    'netIncome' => 20000000,
                    'eps' => 0.50,
                    'period' => 'Q2',
                ],
            ]),
        ]);

        Cache::flush();

        $provider = new FmpMarketDataProvider('https://fmp.test/stable', 'test-key');

        $rows1 = $provider->quarterlyIncomeStatements('AAPL', 8);
        $this->assertCount(1, $rows1);

        // Modify fake response to check if cache is hit
        Http::fake([
            '*/stable/income-statement*' => Http::response([]),
        ]);

        $rows2 = $provider->quarterlyIncomeStatements('AAPL', 8);
        $this->assertCount(1, $rows2); // Returned from cache!
    }

    public function test_when_caching_is_disabled_it_bypasses_cache_remember(): void
    {
        $storageConfig = app(FinancialStorageConfigService::class);
        $storageConfig->disable();

        Http::fake([
            '*/stable/income-statement*' => Http::sequence()
                ->push([
                    [
                        'date' => '2026-06-30',
                        'revenue' => 100000000,
                        'netIncome' => 20000000,
                        'eps' => 0.50,
                        'period' => 'Q2',
                    ],
                ])
                ->push([
                    [
                        'date' => '2026-09-30',
                        'revenue' => 120000000,
                        'netIncome' => 25000000,
                        'eps' => 0.60,
                        'period' => 'Q3',
                    ],
                ]),
        ]);

        Cache::flush();

        $provider = new FmpMarketDataProvider('https://fmp.test/stable', 'test-key');

        $rows1 = $provider->quarterlyIncomeStatements('MSFT', 8);
        $this->assertCount(1, $rows1);
        $this->assertEquals('2026-06-30', $rows1[0]['date']);

        // Second call should hit the sequence second response, NOT the cache
        $rows2 = $provider->quarterlyIncomeStatements('MSFT', 8);
        $this->assertCount(1, $rows2);
        $this->assertEquals('2026-09-30', $rows2[0]['date']);
    }
}
