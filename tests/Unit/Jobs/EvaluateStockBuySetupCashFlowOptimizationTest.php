<?php

use App\Jobs\EvaluateStockBuySetup;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Quarterly cash flow statements are an extra FMP API call per symbol and
 * are only consumed by FCF Margin Expansion / the Growth Synergy Bonus,
 * both disabled by default. EvaluateStockBuySetup::loadFundamentalMetrics()
 * should skip that fetch entirely unless at least one configured setup
 * type actually needs it.
 */
function invokeLoadFundamentalMetrics(
    MarketDataProvider $provider,
    StockFundamentalsAnalyzer $fundamentals,
    string $symbol,
    BuySetupConfigService $configService,
): array {
    $job = new EvaluateStockBuySetup($symbol);
    $method = new ReflectionMethod($job, 'loadFundamentalMetrics');
    $method->setAccessible(true);

    return $method->invoke($job, $provider, $fundamentals, $symbol, $configService);
}

function fakeIncomeRows(): array
{
    return [
        ['date' => '2025-01-01', 'revenue' => 100.0, 'eps' => 1.0, 'net_income' => 10.0],
    ];
}

test('does not fetch quarterly cash flow statements when no setup type needs FCF data', function () {
    $configService = new BuySetupConfigService;
    // Default config: every setup type has fcf_margin_expansion and
    // growth_synergy_bonus disabled.
    $configService->resetToDefaults();

    $provider = Mockery::mock(MarketDataProvider::class);
    $provider->shouldReceive('quarterlyIncomeStatements')->once()->andReturn(fakeIncomeRows());
    $provider->shouldReceive('quarterlyBalanceSheets')->once()->andReturn([]);
    $provider->shouldNotReceive('quarterlyCashFlowStatements');

    $metrics = invokeLoadFundamentalMetrics($provider, new StockFundamentalsAnalyzer, 'TEST1', $configService);

    expect($metrics)->toBeArray()->not->toBeEmpty();
});

test('fetches quarterly cash flow statements when growth synergy bonus is enabled for a setup type', function () {
    $configService = new BuySetupConfigService;
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus']['enabled'] = true;
    $configService->saveConfig($config);

    $provider = Mockery::mock(MarketDataProvider::class);
    $provider->shouldReceive('quarterlyIncomeStatements')->once()->andReturn(fakeIncomeRows());
    $provider->shouldReceive('quarterlyBalanceSheets')->once()->andReturn([]);
    $provider->shouldReceive('quarterlyCashFlowStatements')->once()->andReturn([]);

    $metrics = invokeLoadFundamentalMetrics($provider, new StockFundamentalsAnalyzer, 'TEST2', $configService);

    expect($metrics)->toBeArray()->not->toBeEmpty();
});

test('fetches quarterly cash flow statements when FCF margin expansion scoring is enabled for a setup type', function () {
    $configService = new BuySetupConfigService;
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['score_weights']['fcf_margin_expansion']['enabled'] = true;
    $configService->saveConfig($config);

    $provider = Mockery::mock(MarketDataProvider::class);
    $provider->shouldReceive('quarterlyIncomeStatements')->once()->andReturn(fakeIncomeRows());
    $provider->shouldReceive('quarterlyBalanceSheets')->once()->andReturn([]);
    $provider->shouldReceive('quarterlyCashFlowStatements')->once()->andReturn([]);

    $metrics = invokeLoadFundamentalMetrics($provider, new StockFundamentalsAnalyzer, 'TEST3', $configService);

    expect($metrics)->toBeArray()->not->toBeEmpty();
});

test('isCashFlowDataNeeded returns false by default and true once any setup type opts in', function () {
    $configService = new BuySetupConfigService;
    $configService->resetToDefaults();

    expect($configService->isCashFlowDataNeeded())->toBeFalse();

    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus']['enabled'] = true;
    $configService->saveConfig($config);

    expect($configService->isCashFlowDataNeeded())->toBeTrue();
});
