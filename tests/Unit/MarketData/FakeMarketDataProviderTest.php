<?php

use App\Models\SectorFlowSnapshot;
use App\Services\MarketData\FakeMarketDataProvider;
use App\Services\MarketData\MarketDataProvider;
use App\Services\MoneyFlow\SectorMoneyFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('provides in-memory fake data for all market data provider methods', function () {
    $provider = new FakeMarketDataProvider;

    $provider->dailyBars['AAPL'] = [
        ['date' => '2026-07-16', 'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.5, 'adj_close' => 100.5, 'volume' => 100000],
        ['date' => '2026-07-17', 'open' => 101.0, 'high' => 102.0, 'low' => 100.0, 'close' => 101.5, 'adj_close' => 101.5, 'volume' => 120000],
    ];

    $provider->intradayBars['AAPL'] = [
        ['date' => '2026-07-17 15:00:00', 'open' => 101.0, 'high' => 101.5, 'low' => 100.5, 'close' => 101.2, 'volume' => 10000],
    ];

    $provider->quotes['AAPL'] = ['symbol' => 'AAPL', 'price' => 101.5, 'volume' => 120000];
    $provider->profiles['AAPL'] = ['symbol' => 'AAPL', 'company_name' => 'Apple Inc.'];
    $provider->analystEstimates['AAPL'] = [['symbol' => 'AAPL', 'period' => '2026-09-30', 'eps_avg' => 2.5]];
    $provider->incomeStatements['AAPL'] = [['date' => '2026-06-30', 'revenue' => 1000000]];
    $provider->balanceSheets['AAPL'] = [['date' => '2026-06-30', 'stockholders_equity' => 500000]];
    $provider->cashFlowStatements['AAPL'] = [['date' => '2026-06-30', 'free_cash_flow' => 200000]];
    $provider->screenerRows = [['symbol' => 'AAPL', 'market_cap' => 3000000000000]];
    $provider->calendarRows = [['symbol' => 'AAPL', 'report_date' => '2026-07-30']];
    $provider->surpriseRows = [['symbol' => 'AAPL', 'eps_surprise_percent' => 15.5]];

    $from = CarbonImmutable::parse('2026-07-01');
    $to = CarbonImmutable::parse('2026-07-17');

    expect($provider->historicalDailyBars('aapl', $from, $to))->toHaveCount(2);
    expect($provider->historicalIntradayBars('aapl', '1hour', $from, $to))->toHaveCount(1);
    expect($provider->quote('aapl'))->toEqual(['symbol' => 'AAPL', 'price' => 101.5, 'volume' => 120000]);
    expect($provider->profile('aapl'))->toEqual(['symbol' => 'AAPL', 'company_name' => 'Apple Inc.']);
    expect($provider->analystEstimates('aapl'))->toHaveCount(1);
    expect($provider->quarterlyIncomeStatements('aapl'))->toHaveCount(1);
    expect($provider->quarterlyBalanceSheets('aapl'))->toHaveCount(1);
    expect($provider->quarterlyCashFlowStatements('aapl'))->toHaveCount(1);
    expect($provider->companyScreener())->toHaveCount(1);
    expect($provider->earningsCalendar($from, $to))->toHaveCount(1);
    expect($provider->earningsSurprises($from, $to))->toHaveCount(1);
});

it('supports error tracking and clearing', function () {
    $provider = new FakeMarketDataProvider;
    expect($provider->lastErrors())->toBe([]);

    $provider->errors = [['path' => 'quote', 'status' => 429, 'body' => 'Rate limit']];
    expect($provider->lastErrors())->toHaveCount(1);

    $provider->clearErrors();
    expect($provider->lastErrors())->toBe([]);
});

it('resolves FakeMarketDataProvider from service container when configured', function (string $driver) {
    config(['market_data.provider' => $driver]);

    $resolved = app(MarketDataProvider::class);
    expect($resolved)->toBeInstanceOf(FakeMarketDataProvider::class);
})->with(['fake', 'testing', 'array']);

it('can be injected directly into SectorMoneyFlowService as an artificial provider', function () {
    $provider = new FakeMarketDataProvider;

    $daily = moneyFlowDailySeries();
    $hourly = moneyFlowIntradaySeries();

    $provider->dailyBars['SPY'] = $daily;
    $provider->intradayBars['SPY'] = $hourly;

    foreach (['XLK', 'VGT', 'IYW', 'FTEC', 'XNTK'] as $symbol) {
        $provider->dailyBars[$symbol] = $daily;
        $provider->intradayBars[$symbol] = $hourly;
    }

    app()->instance(MarketDataProvider::class, $provider);

    $summary = app(SectorMoneyFlowService::class)->capture(
        SectorFlowSnapshot::INTERVAL_EOD,
        ['technology'],
        CarbonImmutable::parse('2026-07-17 16:30:00'),
    );

    expect($summary->publishedSectors)->toContain('technology');
    expect(SectorFlowSnapshot::where('sector', 'technology')->exists())->toBeTrue();
});
