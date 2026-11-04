<?php

use App\Services\MarketData\FmpMarketDataProvider;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

test('FmpMarketDataProvider profile preserves missing boolean metadata as null', function () {
    config([
        'market_data.fmp.api_key' => 'test-key',
        'market_data.fmp.base_url' => 'https://financialmodelingprep.com/stable',
    ]);

    Http::fake([
        'https://financialmodelingprep.com/stable/profile?symbol=MISS*' => Http::response([
            [
                'symbol' => 'MISS',
                'companyName' => 'Missing Metadata Corp.',
                'exchange' => 'NYSE',
                'price' => 10.0,
                // isActivelyTrading, isEtf, isFund, isAdr, type are missing
            ],
        ], 200),
    ]);

    $provider = new FmpMarketDataProvider('https://financialmodelingprep.com/stable', 'test-key');
    $profile = $provider->profile('MISS');

    expect($profile)->not->toBeNull()
        ->and($profile['is_actively_trading'])->toBeNull()
        ->and($profile['is_etf'])->toBeNull()
        ->and($profile['is_fund'])->toBeNull()
        ->and($profile['is_adr'])->toBeNull()
        ->and($profile['type'])->toBeNull();
});

test('FmpMarketDataProvider profile converts malformed boolean values to null', function () {
    config([
        'market_data.fmp.api_key' => 'test-key',
        'market_data.fmp.base_url' => 'https://financialmodelingprep.com/stable',
    ]);

    Http::fake([
        'https://financialmodelingprep.com/stable/profile?symbol=MALF*' => Http::response([
            [
                'symbol' => 'MALF',
                'companyName' => 'Malformed Metadata Corp.',
                'isActivelyTrading' => 'not-a-boolean',
                'isEtf' => 'malformed',
                'isFund' => '',
                'isAdr' => 'invalid_flag',
                'type' => 'stock',
            ],
        ], 200),
    ]);

    $provider = new FmpMarketDataProvider('https://financialmodelingprep.com/stable', 'test-key');
    $profile = $provider->profile('MALF');

    expect($profile)->not->toBeNull()
        ->and($profile['is_actively_trading'])->toBeNull()
        ->and($profile['is_etf'])->toBeNull()
        ->and($profile['is_fund'])->toBeNull()
        ->and($profile['is_adr'])->toBeNull()
        ->and($profile['type'])->toBe('stock');
});

test('FmpMarketDataProvider companyScreener preserves missing metadata and handles malformed values', function () {
    config([
        'market_data.fmp.api_key' => 'test-key',
        'market_data.fmp.base_url' => 'https://financialmodelingprep.com/stable',
    ]);

    Http::fake([
        'https://financialmodelingprep.com/stable/company-screener*' => Http::response([
            [
                'symbol' => 'ROW1',
                'companyName' => 'Row Missing Flags',
                // isActivelyTrading, isEtf, isFund, isAdr missing
            ],
            [
                'symbol' => 'ROW2',
                'companyName' => 'Row Valid Flags',
                'isActivelyTrading' => true,
                'isEtf' => false,
                'isFund' => false,
                'isAdr' => false,
                'type' => 'stock',
            ],
            [
                'symbol' => 'ROW3',
                'companyName' => 'Row Malformed Flags',
                'isActivelyTrading' => 'corrupted',
                'isEtf' => 'unknown',
                'isFund' => 12345, // invalid boolean string/value
                'isAdr' => null,
            ],
        ], 200),
    ]);

    $provider = new FmpMarketDataProvider('https://financialmodelingprep.com/stable', 'test-key');
    $rows = $provider->companyScreener();

    expect($rows)->toHaveCount(3);

    // Row 1: missing fields are null
    expect($rows[0]['is_actively_trading'])->toBeNull()
        ->and($rows[0]['is_etf'])->toBeNull()
        ->and($rows[0]['is_fund'])->toBeNull()
        ->and($rows[0]['is_adr'])->toBeNull();

    // Row 2: valid boolean values
    expect($rows[1]['is_actively_trading'])->toBeTrue()
        ->and($rows[1]['is_etf'])->toBeFalse()
        ->and($rows[1]['is_fund'])->toBeFalse()
        ->and($rows[1]['is_adr'])->toBeFalse();

    // Row 3: malformed values resolve to null
    expect($rows[2]['is_actively_trading'])->toBeNull()
        ->and($rows[2]['is_etf'])->toBeNull()
        ->and($rows[2]['is_fund'])->toBeNull()
        ->and($rows[2]['is_adr'])->toBeNull();
});
