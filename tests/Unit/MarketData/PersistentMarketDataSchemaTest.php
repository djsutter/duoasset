<?php

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('stock daily bar persists with retrieved_at timestamp', function () {
    $bar = StockDailyBar::create([
        'symbol' => 'AAPL',
        'bar_date' => '2026-10-20',
        'open' => 150.25,
        'high' => 155.50,
        'low' => 149.75,
        'close' => 154.00,
        'adj_close' => 154.00,
        'volume' => 50000000,
        'retrieved_at' => now(),
    ]);

    expect($bar->exists)->toBeTrue()
        ->and($bar->symbol)->toBe('AAPL')
        ->and((float) $bar->close)->toBe(154.0)
        ->and($bar->retrieved_at)->not->toBeNull();
});

test('market data payload stores and retrieves raw json arrays and enforces uniqueness', function () {
    $payloadData = [
        ['date' => '2026-10-20', 'revenue' => 1000000, 'eps' => 1.25],
        ['date' => '2026-07-20', 'revenue' => 950000, 'eps' => 1.15],
    ];

    $payload = MarketDataPayload::create([
        'symbol' => 'AAPL',
        'data_type' => 'income',
        'period' => 'quarter',
        'payload' => $payloadData,
        'retrieved_at' => now(),
    ]);

    expect($payload->exists)->toBeTrue()
        ->and($payload->payload)->toBeArray()
        ->and($payload->payload[0]['revenue'])->toBe(1000000);

    // Enforce uniqueness on (symbol, data_type, period)
    expect(fn () => MarketDataPayload::create([
        'symbol' => 'AAPL',
        'data_type' => 'income',
        'period' => 'quarter',
        'payload' => ['duplicate' => true],
        'retrieved_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('company profile stores metadata and enforces symbol uniqueness', function () {
    $profile = CompanyProfile::create([
        'symbol' => 'MSFT',
        'company_name' => 'Microsoft Corporation',
        'exchange' => 'NASDAQ',
        'currency' => 'USD',
        'country' => 'US',
        'sector' => 'Technology',
        'industry' => 'Software - Infrastructure',
        'market_cap' => 3000000000000,
        'price' => 420.50,
        'shares_outstanding' => 7400000000,
        'float_shares' => 7300000000,
        'free_float' => 0.985,
        'retrieved_at' => now(),
    ]);

    expect($profile->exists)->toBeTrue()
        ->and($profile->symbol)->toBe('MSFT')
        ->and($profile->company_name)->toBe('Microsoft Corporation')
        ->and((float) $profile->price)->toBe(420.50)
        ->and($profile->market_cap)->toBe(3000000000000);

    expect(fn () => CompanyProfile::create([
        'symbol' => 'MSFT',
        'company_name' => 'Duplicate Microsoft',
    ]))->toThrow(QueryException::class);
});

test('company financial stores structured metrics and enforces statement uniqueness', function () {
    $financial = CompanyFinancial::create([
        'symbol' => 'NVDA',
        'statement_type' => CompanyFinancial::TYPE_INCOME,
        'fiscal_date' => '2026-07-31',
        'period' => 'Q2',
        'fiscal_year' => 2026,
        'revenue' => 30000000000.00,
        'net_income' => 16000000000.00,
        'eps' => 0.68,
        'stockholders_equity' => null,
        'free_cash_flow' => null,
        'reported_currency' => 'USD',
        'raw_metrics' => ['grossProfit' => 22000000000],
        'retrieved_at' => now(),
    ]);

    expect($financial->exists)->toBeTrue()
        ->and($financial->symbol)->toBe('NVDA')
        ->and((float) $financial->revenue)->toBe(30000000000.00)
        ->and((float) $financial->eps)->toBe(0.68)
        ->and($financial->raw_metrics['grossProfit'])->toBe(22000000000);

    expect(fn () => CompanyFinancial::create([
        'symbol' => 'NVDA',
        'statement_type' => CompanyFinancial::TYPE_INCOME,
        'fiscal_date' => '2026-07-31',
        'period' => 'Q2',
        'fiscal_year' => 2026,
    ]))->toThrow(QueryException::class);
});
