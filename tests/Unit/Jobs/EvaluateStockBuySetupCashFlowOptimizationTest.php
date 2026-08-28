<?php

use App\Jobs\EvaluateStockBuySetup;
use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockFundamentalsAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Quarterly cash flow statements are an extra FMP API call per symbol and
 * are only consumed by FCF Margin Expansion / the Growth Synergy Bonus.
 * FCF Margin Expansion is enabled by default for heartbeat_consolidation_spike
 * (the only setup type enabled by default), so the fetch happens by default;
 * EvaluateStockBuySetup::loadFundamentalMetrics() should still skip it
 * entirely once every configured setup type has both opted out.
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
    $configService->resetToDefaults();

    // FCF Margin Expansion is enabled by default for heartbeat_consolidation_spike;
    // explicitly opt every setup type out to exercise the "nothing needs FCF data" path.
    $config = $configService->getConfig();
    foreach ($config['setup_types'] as $key => $setupType) {
        $config['setup_types'][$key]['score_weights']['fcf_margin_expansion']['enabled'] = false;
        $config['setup_types'][$key]['growth_synergy_bonus']['enabled'] = false;
    }
    $configService->saveConfig($config);

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

test('isCashFlowDataNeeded returns true by default (FCF Margin Expansion enabled) and false once every setup type opts out', function () {
    $configService = new BuySetupConfigService;
    $configService->resetToDefaults();

    // heartbeat_consolidation_spike ships with fcf_margin_expansion enabled by default.
    expect($configService->isCashFlowDataNeeded())->toBeTrue();

    $config = $configService->getConfig();
    foreach ($config['setup_types'] as $key => $setupType) {
        $config['setup_types'][$key]['score_weights']['fcf_margin_expansion']['enabled'] = false;
        $config['setup_types'][$key]['growth_synergy_bonus']['enabled'] = false;
    }
    $configService->saveConfig($config);

    expect($configService->isCashFlowDataNeeded())->toBeFalse();
});
