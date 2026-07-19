<?php

/**
 * Phase 1: validate the Sector Money Flows configuration is well-formed and
 * matches the approved universe. These guard the single source of truth in
 * config/market_data.php before any engine consumes it.
 */

/** @return array<int, string> */
function approvedMoneyflowSectors(): array
{
    return [
        'technology',
        'financials',
        'healthcare',
        'communication_services',
        'consumer_discretionary',
        'consumer_staples',
        'industrials',
        'energy',
        'utilities',
        'real_estate',
        'materials',
    ];
}

it('defines exactly the 11 approved GICS sector keys', function () {
    $keys = array_keys(config('market_data.sector_etfs'));

    expect($keys)->toEqualCanonicalizing(approvedMoneyflowSectors());
});

it('gives every sector a label, existing_sector_slug and 5 weighted ETFs', function () {
    foreach (config('market_data.sector_etfs') as $key => $sector) {
        expect($sector)->toHaveKeys(['label', 'existing_sector_slug', 'etfs'], "sector [$key]");
        expect($sector['label'])->toBeString()->not->toBe('');
        expect($sector['existing_sector_slug'])->toBeString()->not->toBe('');
        expect($sector['etfs'])->toHaveCount(5, "sector [$key] should list 5 ETFs");

        foreach ($sector['etfs'] as $issuer => $etf) {
            expect($etf)->toHaveKeys(['symbol', 'weight'], "sector [$key] issuer [$issuer]");
            expect($etf['symbol'])->toBeString()->not->toBe('');
            expect($etf['weight'])->toBeNumeric();
        }
    }
});

it('assigns the corrected Communication Services ETFs and never IYW', function () {
    $symbols = array_map(
        fn (array $etf) => $etf['symbol'],
        config('market_data.sector_etfs.communication_services.etfs'),
    );

    expect($symbols)->toEqualCanonicalizing(['XLC', 'VOX', 'IYZ', 'RSPC', 'FCOM']);
    expect($symbols)->not->toContain('IYW');
});

it('keeps IYW in Technology only', function () {
    expect(config('market_data.sector_etfs.technology.etfs.ishares.symbol'))->toBe('IYW');

    foreach (config('market_data.sector_etfs') as $key => $sector) {
        if ($key === 'technology') {
            continue;
        }
        $symbols = array_map(fn (array $etf) => $etf['symbol'], $sector['etfs']);
        expect($symbols)->not->toContain('IYW', "sector [$key] must not reuse IYW");
    }
});

it('uses no duplicate ETF symbol across the whole universe', function () {
    $all = [];
    foreach (config('market_data.sector_etfs') as $sector) {
        foreach ($sector['etfs'] as $etf) {
            $all[] = $etf['symbol'];
        }
    }

    expect($all)->toHaveCount(55);
    expect(array_unique($all))->toHaveCount(55);
});

it('maps existing taxonomy slugs, collapsing consumer and renaming comms/real estate', function () {
    $etfs = config('market_data.sector_etfs');

    expect($etfs['consumer_discretionary']['existing_sector_slug'])->toBe('consumer');
    expect($etfs['consumer_staples']['existing_sector_slug'])->toBe('consumer');
    expect($etfs['communication_services']['existing_sector_slug'])->toBe('telecommunications');
    expect($etfs['real_estate']['existing_sector_slug'])->toBe('real-estate');
});

it('exposes moneyflow engine settings with SPY benchmark and configurable timeframes', function () {
    expect(config('market_data.moneyflow.enabled'))->toBeBool();
    expect(config('market_data.moneyflow.benchmark_symbol'))->toBe('SPY');
    expect(config('market_data.moneyflow.history_lookback_days'))->toBeInt()->toBeGreaterThan(30);
    expect(config('market_data.moneyflow.market_timezone'))->toBeString()->not->toBe('');

    // All four timeframes are configurable, positive integers. Values are
    // operator-tunable (do not hard-code the exact defaults here).
    foreach (['hourly', 'daily', 'weekly', 'monthly'] as $tf) {
        expect(config("market_data.moneyflow.periods.$tf"))->toBeInt()->toBeGreaterThan(0);
    }

    expect(config('market_data.moneyflow.intraday.interval'))->toBeString()->not->toBe('');
});

it('exposes scoring, confidence and direction weights for the engine', function () {
    $weights = config('market_data.moneyflow.score_weights');
    expect($weights)->toHaveKeys(['change', 'relative_strength', 'relative_volume']);

    expect(config('market_data.moneyflow.timeframe_weights'))
        ->toHaveKeys(['hourly', 'daily', 'weekly', 'monthly']);

    expect(config('market_data.moneyflow.confidence.min_etfs_to_publish'))->toBeInt()->toBeGreaterThan(0);
    expect(config('market_data.moneyflow.confidence.levels'))->toBeArray();

    expect(config('market_data.moneyflow.direction'))
        ->toHaveKeys(['strong_strength', 'weak_strength', 'velocity_band', 'acceleration_band']);
});
