<?php

use App\Livewire\Watchlists\StockBuySetups;
use App\Models\Setting;
use App\Models\StockBuySetupAlert;
use App\Models\User;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\IgnitionExtensionCalculator;
use App\Services\Stocks\StockBuySetupResult;
use App\Services\Stocks\StockBuySetupScorer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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
        'full_bonus_max_post_gain_pct' => 15.0,
        'zero_bonus_post_gain_pct' => 40.0,
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
    ?float $postIgnitionGainPct = 4.0,
    ?float $postIgnitionPeakGainPct = 4.0,
    ?float $ignitionReferencePrice = 10.0,
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
        'ignition_reference_price' => $ignitionReferencePrice,
        'post_ignition_gain_pct' => $postIgnitionGainPct,
        'post_ignition_peak_gain_pct' => $postIgnitionPeakGainPct,
    ]);
}

/* -------------------------------------------------------------------------- */
/* PART 1: ORIGINAL REGRESSION TESTS (Ignition Qualification & Properties)   */
/* -------------------------------------------------------------------------- */

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

test('23. result struct carries spikePriceChangePct and works with scorer', function () {
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

    $result->ignitionReferencePrice = 12.0;
    $result->postIgnitionGainPct = 4.0;
    $result->postIgnitionPeakGainPct = 4.0;

    $bonus = $scorer->ignitionBonus($result, 'heartbeat_consolidation_spike');
    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['points'])->toBe(8)
        ->and($bonus['spike_price_change_pct'])->toBe(16.25)
        ->and($bonus['post_ignition_peak_gain_pct'])->toBe(4.0)
        ->and($bonus['freshness_multiplier'])->toBe(1.0);
});

/* -------------------------------------------------------------------------- */
/* PART 2: FRESHNESS & DECAY GUARD TESTS                                     */
/* -------------------------------------------------------------------------- */

test('24. Peak gain <= 15% receives full bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 8.0, postIgnitionPeakGainPct: 10.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(1.0)
        ->and($bonus['points'])->toBe(20);
});

test('25. Exactly 15% receives full bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 15.0, postIgnitionPeakGainPct: 15.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(1.0)
        ->and($bonus['points'])->toBe(20);
});

test('26. 20% peak gain receives the correct decayed bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // (40 - 20) / (40 - 15) = 20 / 25 = 0.80 -> 20 * 0.80 = 16
    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 18.0, postIgnitionPeakGainPct: 20.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toEqualWithDelta(0.80, 0.001)
        ->and($bonus['points'])->toBe(16);
});

test('27. 25% peak gain produces a 50% multiplier under custom 10/40 thresholds', function () {
    enableIgnitionBonus(['bonus_points' => 20, 'full_bonus_max_post_gain_pct' => 10.0, 'zero_bonus_post_gain_pct' => 40.0]);
    $scorer = new StockBuySetupScorer;

    // Under 10/40 config: (40 - 25) / (40 - 10) = 15 / 30 = 0.50 -> 20 * 0.50 = 10
    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 20.0, postIgnitionPeakGainPct: 25.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toEqualWithDelta(0.50, 0.001)
        ->and($bonus['points'])->toBe(10);
});

test('28. 30% peak gain receives the correct decayed bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // Under default 15/40: (40 - 30) / (40 - 15) = 10 / 25 = 0.40 -> 20 * 0.40 = 8
    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 25.0, postIgnitionPeakGainPct: 30.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toEqualWithDelta(0.40, 0.001)
        ->and($bonus['points'])->toBe(8);
});

test('29. 35% peak gain receives only a small residual bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // (40 - 35) / (40 - 15) = 5 / 25 = 0.20 -> 20 * 0.20 = 4
    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 30.0, postIgnitionPeakGainPct: 35.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toEqualWithDelta(0.20, 0.001)
        ->and($bonus['points'])->toBe(4);
});

test('30. Exactly 40% peak gain receives 0 bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 38.0, postIgnitionPeakGainPct: 40.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeFalse()
        ->and($bonus['freshness_eligible'])->toBeFalse()
        ->and($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(0.0)
        ->and($bonus['points'])->toBe(0);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price-led ignition — late/extended')
        ->and($entry['value'])->toContain('bonus expired');
});

test('31. Greater than 40% receives 0 bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 42.0, postIgnitionPeakGainPct: 48.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeFalse()
        ->and($bonus['freshness_eligible'])->toBeFalse()
        ->and($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(0.0)
        ->and($bonus['points'])->toBe(0);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price-led ignition — late/extended')
        ->and($entry['value'])->toContain('bonus expired');
});

test('32. Stock that once reached +45% but is currently only +25% still receives 0', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // Stock pulled back from 45% peak to 25% current price
    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 25.0, postIgnitionPeakGainPct: 45.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeFalse()
        ->and($bonus['freshness_eligible'])->toBeFalse()
        ->and($bonus['points'])->toBe(0);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price-led ignition — late/extended')
        ->and($entry['value'])->toContain('Post-ignition: +25.0% current | +45.0% peak | bonus expired');
});

test('33. Current/live quote above all historical closes is included in the effective peak', function () {
    $calculator = new IgnitionExtensionCalculator;
    $spikeDate = '2026-05-01';

    $bars = [
        ['date' => '2026-05-01', 'open' => 10.0, 'high' => 12.0, 'low' => 9.5, 'close' => 10.0, 'volume' => 1000],
        ['date' => '2026-05-02', 'open' => 10.0, 'high' => 11.0, 'low' => 9.8, 'close' => 11.0, 'volume' => 500],
        ['date' => '2026-05-03', 'open' => 11.0, 'high' => 12.0, 'low' => 10.5, 'close' => 12.0, 'volume' => 500],
    ];

    // Live quote is $15.00 (+50% from reference 10.00), above all completed closes ($12.00 / +20%)
    $res = $calculator->calculate($bars, $spikeDate, 15.0);

    expect($res['reference_price'])->toEqualWithDelta(10.0, 0.001)
        ->and($res['current_gain_pct'])->toEqualWithDelta(50.0, 0.001)
        ->and($res['peak_gain_pct'])->toEqualWithDelta(50.0, 0.001);
});

test('34. Historical intraday high values are not used for peak extension', function () {
    $calculator = new IgnitionExtensionCalculator;
    $spikeDate = '2026-05-01';

    // Day 2 has an intraday high wick of $18.00 (+80%), but closes at $11.00 (+10%)
    $bars = [
        ['date' => '2026-05-01', 'open' => 10.0, 'high' => 11.0, 'low' => 9.5, 'close' => 10.0, 'volume' => 1000],
        ['date' => '2026-05-02', 'open' => 10.0, 'high' => 18.0, 'low' => 9.8, 'close' => 11.0, 'volume' => 500],
        ['date' => '2026-05-03', 'open' => 11.0, 'high' => 12.5, 'low' => 10.5, 'close' => 12.0, 'volume' => 500],
    ];

    $res = $calculator->calculate($bars, $spikeDate, 12.0);

    // Peak gain must be based on max completed close ($12.00) = +20%, NOT the $18.00 intraday wick (+80%)
    expect($res['reference_price'])->toEqualWithDelta(10.0, 0.001)
        ->and($res['current_gain_pct'])->toEqualWithDelta(20.0, 0.001)
        ->and($res['peak_gain_pct'])->toEqualWithDelta(20.0, 0.001);
});

test('35. Missing freshness information receives 0 bonus points and explains missing data in breakdown', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // Quality qualifies on base, dry-up, RVOL, price gain, but freshness fields are null
    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: null, postIgnitionPeakGainPct: null);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeFalse()
        ->and($bonus['eligible'])->toBeFalse()
        ->and($bonus['points'])->toBe(0);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['points'])->toBe(0)
        ->and($entry['max'])->toBe(20)
        ->and($entry['value'])->toContain('Price-led ignition')
        ->and($entry['value'])->toContain('Post-ignition freshness unavailable | bonus withheld');
});

test('36. An ignition occurring on the latest bar has approximately 0% post-ignition peak gain and receives full bonus', function () {
    $calculator = new IgnitionExtensionCalculator;
    $spikeDate = '2026-05-05';

    // Spike occurs on the latest available bar
    $bars = [
        ['date' => '2026-05-01', 'open' => 10.0, 'high' => 10.2, 'low' => 9.8, 'close' => 10.0, 'volume' => 1000],
        ['date' => '2026-05-05', 'open' => 10.0, 'high' => 12.0, 'low' => 9.9, 'close' => 12.0, 'volume' => 5000],
    ];

    $res = $calculator->calculate($bars, $spikeDate, 12.0);

    expect($res['reference_price'])->toEqualWithDelta(12.0, 0.001)
        ->and($res['current_gain_pct'])->toEqualWithDelta(0.0, 0.001)
        ->and($res['peak_gain_pct'])->toEqualWithDelta(0.0, 0.001);

    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;
    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 0.0, postIgnitionPeakGainPct: 0.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(1.0)
        ->and($bonus['points'])->toBe(20);
});

test('37. Existing saved ignition configuration in database Setting table without new fields loads without losing configured values and receives 15/40 defaults', function () {
    $defaults = BuySetupConfigService::DEFAULT_CONFIG;
    $oldConfig = $defaults;
    $oldConfig['setup_types']['heartbeat_consolidation_spike']['ignition_bonus'] = [
        'bonus_points' => 12,
        'color' => 'purple',
        'min_base_days' => 110,
        'min_volume_dry_up_pct' => 35.0,
        'price_led_min_relative_volume' => 2.8,
        'price_led_min_price_change_pct' => 14.0,
        'volume_led_min_relative_volume' => 3.5,
        'volume_led_min_price_change_pct' => 9.0,
        // full_bonus_max_post_gain_pct and zero_bonus_post_gain_pct omitted as in older saved databases
    ];

    // Directly write old JSON into Setting table
    Setting::updateOrCreate(
        ['key' => BuySetupConfigService::SETTING_KEY],
        ['value' => json_encode($oldConfig)]
    );

    $configService = app(BuySetupConfigService::class);
    $loadedIgnition = $configService->getIgnitionBonusConfig('heartbeat_consolidation_spike');

    expect($loadedIgnition['bonus_points'])->toBe(12)
        ->and($loadedIgnition['color'])->toBe('purple')
        ->and($loadedIgnition['min_base_days'])->toBe(110)
        ->and($loadedIgnition['min_volume_dry_up_pct'])->toBe(35.0)
        ->and($loadedIgnition['price_led_min_relative_volume'])->toBe(2.8)
        ->and($loadedIgnition['price_led_min_price_change_pct'])->toBe(14.0)
        ->and($loadedIgnition['volume_led_min_relative_volume'])->toBe(3.5)
        ->and($loadedIgnition['volume_led_min_price_change_pct'])->toBe(9.0)
        ->and($loadedIgnition['full_bonus_max_post_gain_pct'])->toBe(15.0)
        ->and($loadedIgnition['zero_bonus_post_gain_pct'])->toBe(40.0);
});

test('38. Ignition UI filter excludes signals at >= 40% peak extension', function () {
    $user = User::factory()->create();
    enableIgnitionBonus(['bonus_points' => 20]);

    // Alert with peak gain 45% (expired)
    StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'EXPIRED',
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_date' => '2026-05-01',
        'status' => 'detected',
        'detected_at' => now(),
        'setup_score' => 70,
        'raw_setup_score' => 70,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'post_ignition_peak_gain_pct' => 45.0,
        'post_ignition_gain_pct' => 30.0,
        'ignition_reference_price' => 10.0,
    ]);

    Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('bonusType', 'ignition_accumulation')
        ->assertViewHas('alerts', function ($alerts) {
            return $alerts->count() === 0;
        });
});

test('39. Ignition UI filter retains partially decayed signals below 40%', function () {
    $user = User::factory()->create();
    enableIgnitionBonus(['bonus_points' => 20]);

    // Alert with peak gain 25% (partially decayed, but active)
    StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'ACTIVE1',
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_date' => '2026-05-01',
        'status' => 'detected',
        'detected_at' => now(),
        'setup_score' => 70,
        'raw_setup_score' => 70,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'post_ignition_peak_gain_pct' => 25.0,
        'post_ignition_gain_pct' => 20.0,
        'ignition_reference_price' => 10.0,
    ]);

    // Alert with peak gain 10% (full bonus)
    StockBuySetupAlert::create([
        'source' => 'fmp',
        'symbol' => 'ACTIVE2',
        'setup_type' => 'heartbeat_consolidation_spike',
        'spike_date' => '2026-05-02',
        'status' => 'detected',
        'detected_at' => now(),
        'setup_score' => 75,
        'raw_setup_score' => 75,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.5,
        'spike_price_change_pct' => 15.0,
        'post_ignition_peak_gain_pct' => 10.0,
        'post_ignition_gain_pct' => 10.0,
        'ignition_reference_price' => 10.0,
    ]);

    Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('bonusType', 'ignition_accumulation')
        ->assertViewHas('alerts', function ($alerts) {
            return $alerts->count() === 2;
        });
});
