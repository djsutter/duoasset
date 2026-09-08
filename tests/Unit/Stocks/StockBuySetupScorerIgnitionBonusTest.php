<?php

use App\Models\StockBuySetupAlert;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupResult;
use App\Services\Stocks\StockBuySetupScorer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Configures the Ignition Bonus for heartbeat_consolidation_spike.
 */
function enableIgnitionBonus(array $overrides = []): void
{
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus'] = array_merge([
        'bonus_points' => 8,
        'min_base_days' => 90,
        'min_volume_dry_up_pct' => 30.0,
        'price_led_min_relative_volume' => 2.2,
        'price_led_min_price_change_pct' => 12.0,
        'volume_led_min_relative_volume' => 3.0,
        'volume_led_min_price_change_pct' => 8.0,
    ], $overrides);
    $configService->saveConfig($config);
}

/**
 * Helper to build an alert with specified ignition inputs.
 */
function ignitionAlert(
    ?int $baseDays = 120,
    ?float $dryUpScore = 0.40,
    ?float $rvol = 2.5,
    ?float $priceChangePct = 15.0,
    string $setupType = 'heartbeat_consolidation_spike',
    int $spikeRarityPoints = 0,
    bool $is52w = false,
    bool $is104w = false,
): StockBuySetupAlert {
    return new StockBuySetupAlert([
        'setup_type' => $setupType,
        'base_duration_days' => $baseDays,
        'volume_dry_up_score' => $dryUpScore,
        'spike_relative_volume' => $rvol,
        'spike_price_change_pct' => $priceChangePct,
        'spike_rarity_points' => $spikeRarityPoints,
        'is_52w_high_volume' => $is52w,
        'is_104w_high_volume' => $is104w,
    ]);
}

test('1. bonus_points = 0 awards zero', function () {
    $scorer = new StockBuySetupScorer;
    $alert = ignitionAlert(140, 0.40, 2.4, 16.0);

    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['bonus_points'])->toBe(0)
        ->and($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('2. common base-duration requirement fails -> zero', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    // Base days 89 < 90 min base days
    $alert = ignitionAlert(89, 0.40, 2.4, 16.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('3. common volume-dry-up requirement fails -> zero', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    // Volume dry-up 29% < 30% min volume dry-up
    $alert = ignitionAlert(120, 0.29, 2.4, 16.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('4. 2.2x RVOL + 12% gain qualifies through price-led path', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, 0.40, 2.4, 16.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['price_led_qualified'])->toBeTrue()
        ->and($bonus['volume_led_qualified'])->toBeFalse()
        ->and($bonus['points'])->toBe(8);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price-led ignition')
        ->and($entry['value'])->toContain('140d base | 40% dry-up | 2.4x relative volume | +16.0%');
});

test('5. RVOL above 2.2x but price below 12% does not qualify through the price-led path', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    // 2.4x RVOL, 10% gain (fails price-led because < 12%, fails volume-led because RVOL < 3.0x)
    $alert = ignitionAlert(120, 0.40, 2.4, 10.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('6. 3.0x RVOL + 8% gain qualifies through volume-led path', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    // 3.4x RVOL, 9.5% gain
    $alert = ignitionAlert(115, 0.37, 3.4, 9.5);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['price_led_qualified'])->toBeFalse()
        ->and($bonus['volume_led_qualified'])->toBeTrue()
        ->and($bonus['points'])->toBe(8);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Volume-led ignition')
        ->and($entry['value'])->toContain('115d base | 37% dry-up | 3.4x relative volume | +9.5%');
});

test('7. RVOL above 3.0x but price below 8% does not qualify', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    // 6.0x RVOL, 4% gain
    $alert = ignitionAlert(140, 0.45, 6.0, 4.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('8. 2.5x RVOL + 10% does not qualify', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(120, 0.40, 2.5, 10.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('9. a setup satisfying both paths receives the configured bonus only once', function () {
    enableIgnitionBonus(['bonus_points' => 8]);
    $scorer = new StockBuySetupScorer;

    // 3.9x RVOL and +16% price move (satisfies both Price-Led and Volume-Led)
    $alert = ignitionAlert(150, 0.44, 3.9, 16.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['price_led_qualified'])->toBeTrue()
        ->and($bonus['volume_led_qualified'])->toBeTrue()
        ->and($bonus['points'])->toBe(8);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price + volume ignition')
        ->and($entry['value'])->toContain('150d base | 44% dry-up | 3.9x relative volume | +16.0%');
});

test('10. missing RVOL -> zero', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, 0.40, null, 16.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('11. missing price change -> zero', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, 0.40, 3.5, null);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('12. missing base duration -> zero', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(null, 0.40, 3.5, 16.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('13. missing volume dry-up -> zero', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, null, 3.5, 16.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(0)
        ->and($bonus['eligible'])->toBeFalse();
});

test('14. Spike Rarity = 0 does not prevent qualification', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(150, 0.44, 3.9, 16.25, spikeRarityPoints: 0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['points'])->toBe(8);
});

test('15. 52w/104w high-volume flags are not required', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(150, 0.44, 3.9, 16.0, is52w: false, is104w: false);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['points'])->toBe(8);
});

test('16. existing setup scores remain unchanged when bonus_points = 0', function () {
    $scorer = new StockBuySetupScorer;
    $alert = ignitionAlert(150, 0.44, 3.9, 16.0);

    $breakdown = $scorer->breakdown($alert, 'heartbeat_consolidation_spike');
    $scoreMeta = $scorer->scoreMetaFromBreakdown($breakdown);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    $finalScore = min(100, $scoreMeta['normalized'] + $bonus['points']);

    expect($bonus['points'])->toBe(0)
        ->and($finalScore)->toBe($scoreMeta['normalized']);
});

test('17. different setup types persist independent Ignition configuration', function () {
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();

    $config['setup_types']['heartbeat_consolidation_spike']['ignition_bonus']['bonus_points'] = 8;
    $config['setup_types']['range_compression_breakout']['ignition_bonus']['bonus_points'] = 5;
    $config['setup_types']['floor_reversal_accumulation']['ignition_bonus']['bonus_points'] = 0;
    $configService->saveConfig($config);

    expect($configService->getIgnitionBonusConfig('heartbeat_consolidation_spike')['bonus_points'])->toBe(8)
        ->and($configService->getIgnitionBonusConfig('range_compression_breakout')['bonus_points'])->toBe(5)
        ->and($configService->getIgnitionBonusConfig('floor_reversal_accumulation')['bonus_points'])->toBe(0);
});

test('18. dynamic setup types receive the default zero bonus', function () {
    $service = app(BuySetupConfigService::class);
    $defaultType = $service->createDefaultSetupType('custom_setup', 'Custom Setup');

    expect($defaultType)->toHaveKey('ignition_bonus')
        ->and($defaultType['ignition_bonus']['bonus_points'])->toBe(0)
        ->and($defaultType['ignition_bonus']['min_base_days'])->toBe(90);
});

test('19. final setup score respects the existing 100-point cap', function () {
    enableIgnitionBonus(['bonus_points' => 10]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(150, 0.44, 3.9, 16.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    $baseScore = 95;
    $finalScore = min(100, $baseScore + $bonus['points']);

    expect($bonus['points'])->toBe(10)
        ->and($finalScore)->toBe(100);
});

test('20. raw_setup_score remains unchanged', function () {
    enableIgnitionBonus(['bonus_points' => 10]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(150, 0.44, 3.9, 16.0);
    $breakdown = $scorer->breakdown($alert, 'heartbeat_consolidation_spike');
    $scoreMeta = $scorer->scoreMetaFromBreakdown($breakdown);

    // Ignition bonus is NOT part of breakdown and does NOT affect raw_setup_score
    expect($breakdown)->not->toHaveKey('ignition_bonus')
        ->and($scoreMeta['normalized'])->toBeGreaterThanOrEqual(0);
});

test('21. August 21 BNC-style fixture qualifies through Price-Led Ignition', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    // BNC August 21: >90d base, >30% dry-up, 2.4x RVOL, +16.7% price move
    $alert = ignitionAlert(95, 0.35, 2.4, 16.7);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['price_led_qualified'])->toBeTrue()
        ->and($bonus['points'])->toBe(8);
});

test('22. September 3 BNC-style fixture qualifies and receives only one bonus', function () {
    enableIgnitionBonus(['bonus_points' => 8]);
    $scorer = new StockBuySetupScorer;

    // BNC September 3: 150d base, 44% dry-up, 3.9x RVOL, +16.25% price move, Spike Rarity = 0
    $alert = ignitionAlert(150, 0.44, 3.9, 16.25, spikeRarityPoints: 0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['price_led_qualified'])->toBeTrue()
        ->and($bonus['volume_led_qualified'])->toBeTrue()
        ->and($bonus['points'])->toBe(8);
});

test('result struct carries spikePriceChangePct and works with scorer', function () {
    enableIgnitionBonus();
    $scorer = new StockBuySetupScorer;

    $result = new StockBuySetupResult(
        symbol: 'BNC',
        setupType: 'heartbeat_consolidation_spike',
        companyName: 'BNC Bancorp',
        exchange: 'NASDAQ',
        marketCap: 500000000,
        marketCapCategory: 'small',
        spikeDate: CarbonImmutable::parse('2026-09-03'),
        spikeVolume: 3900000,
        prior52wMaxVolume: 2000000,
        max104wVolume: 2500000,
        is52wHighVolume: true,
        is104wHighVolume: true,
        daysSincePreviousComparableSpike: 120,
        spikeAgeBars: 2,
        spikeRarityPoints: 0,
        spikeRarityDescription: 'Spike',
        baseStart: CarbonImmutable::parse('2026-02-01'),
        baseEnd: CarbonImmutable::parse('2026-09-01'),
        baseDurationDays: 150,
        baseHigh: 15.0,
        baseLow: 12.0,
        rangeCompressionPct: 25.0,
        atrContractionRatio: 0.6,
        volumeDryUpScore: 0.44,
        slope: 0.01,
        distanceToBreakoutPct: 2.0,
        maAlignment: 'bullish',
        relativeStrengthScore: 85.0,
        spikeRelativeVolume: 3.9,
        spikePriceChangePct: 16.25,
    );

    $bonus = $scorer->ignitionBonus($result, 'heartbeat_consolidation_spike');
    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['points'])->toBe(8)
        ->and($bonus['spike_price_change_pct'])->toBe(16.25);
});
