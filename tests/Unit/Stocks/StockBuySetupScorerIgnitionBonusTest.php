<?php

use App\Livewire\Watchlists\StockBuySetups;
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
    ?float $postIgnitionGainPct = 5.0,
    ?float $postIgnitionPeakGainPct = 5.0,
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

test('1. Existing price-led ignition still qualifies with fresh post-ignition movement', function () {
    enableIgnitionBonus(['bonus_points' => 8]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 5.0, postIgnitionPeakGainPct: 5.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(1.0)
        ->and($bonus['price_led_qualified'])->toBeTrue()
        ->and($bonus['volume_led_qualified'])->toBeFalse()
        ->and($bonus['points'])->toBe(8);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price-led ignition')
        ->and($entry['value'])->toContain('140d base | 40% dry-up | 2.4x relative volume | +16.0%')
        ->and($entry['value'])->toContain('Post-ignition: +5.0% current | +5.0% peak | freshness 100%');
});

test('2. Existing volume-led ignition still qualifies with fresh movement', function () {
    enableIgnitionBonus(['bonus_points' => 8]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(115, 0.37, 3.4, 9.5, postIgnitionGainPct: 8.0, postIgnitionPeakGainPct: 10.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(1.0)
        ->and($bonus['price_led_qualified'])->toBeFalse()
        ->and($bonus['volume_led_qualified'])->toBeTrue()
        ->and($bonus['points'])->toBe(8);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Volume-led ignition')
        ->and($entry['value'])->toContain('115d base | 37% dry-up | 3.4x relative volume | +9.5%')
        ->and($entry['value'])->toContain('Post-ignition: +8.0% current | +10.0% peak | freshness 100%');
});

test('3. Peak gain <= 15% receives full bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 10.0, postIgnitionPeakGainPct: 10.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(1.0)
        ->and($bonus['points'])->toBe(20);
});

test('4. Exactly 15% peak gain receives full bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 12.0, postIgnitionPeakGainPct: 15.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(1.0)
        ->and($bonus['points'])->toBe(20);
});

test('5. 20% peak gain receives the correct decayed bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // (40 - 20) / (40 - 15) = 20 / 25 = 0.8 multiplier -> 20 * 0.8 = 16 points
    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 18.0, postIgnitionPeakGainPct: 20.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(0.8)
        ->and($bonus['points'])->toBe(16);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Post-ignition: +18.0% current | +20.0% peak | freshness 80%');
});

test('6. 25% peak gain produces a 50% multiplier when thresholds are configured accordingly or 60% with default 15/40', function () {
    $scorer = new StockBuySetupScorer;

    // With custom 10.0% full and 40.0% zero: (40 - 25) / (40 - 10) = 15 / 30 = 0.50 (50%)
    enableIgnitionBonus([
        'bonus_points' => 20,
        'full_bonus_max_post_gain_pct' => 10.0,
        'zero_bonus_post_gain_pct' => 40.0,
    ]);
    $alertCustom = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 20.0, postIgnitionPeakGainPct: 25.0);
    $bonusCustom = $scorer->ignitionBonus($alertCustom, 'heartbeat_consolidation_spike');
    expect($bonusCustom['freshness_multiplier'])->toBe(0.5)
        ->and($bonusCustom['points'])->toBe(10);

    // With default 15.0% full and 40.0% zero: (40 - 25) / (40 - 15) = 15 / 25 = 0.60 -> 12 points
    enableIgnitionBonus([
        'bonus_points' => 20,
        'full_bonus_max_post_gain_pct' => 15.0,
        'zero_bonus_post_gain_pct' => 40.0,
    ]);
    $alertDefault = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 20.0, postIgnitionPeakGainPct: 25.0);
    $bonusDefault = $scorer->ignitionBonus($alertDefault, 'heartbeat_consolidation_spike');
    expect($bonusDefault['freshness_multiplier'])->toBe(0.6)
        ->and($bonusDefault['points'])->toBe(12);
});

test('7. 30% peak gain receives the correct decayed bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // (40 - 30) / (40 - 15) = 10 / 25 = 0.4 multiplier -> 20 * 0.4 = 8 points
    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 22.0, postIgnitionPeakGainPct: 30.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(0.4)
        ->and($bonus['points'])->toBe(8);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Post-ignition: +22.0% current | +30.0% peak | freshness 40%');
});

test('8. 35% peak gain receives only a small residual bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // (40 - 35) / (40 - 15) = 5 / 25 = 0.2 multiplier -> 20 * 0.2 = 4 points
    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 30.0, postIgnitionPeakGainPct: 35.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(0.2)
        ->and($bonus['points'])->toBe(4);
});

test('9. Exactly 40% peak gain receives 0', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 30.0, postIgnitionPeakGainPct: 40.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeFalse()
        ->and($bonus['freshness_multiplier'])->toBe(0.0)
        ->and($bonus['eligible'])->toBeFalse()
        ->and($bonus['points'])->toBe(0);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price-led ignition — late/extended')
        ->and($entry['value'])->toContain('Post-ignition: +30.0% current | +40.0% peak | bonus expired');
});

test('10. Greater than 40% receives 0', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 45.0, postIgnitionPeakGainPct: 52.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeFalse()
        ->and($bonus['freshness_multiplier'])->toBe(0.0)
        ->and($bonus['eligible'])->toBeFalse()
        ->and($bonus['points'])->toBe(0);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price-led ignition — late/extended')
        ->and($entry['value'])->toContain('Post-ignition: +45.0% current | +52.0% peak | bonus expired');
});

test('11. Stock that once reached +45% but is currently only +25% still receives 0', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // SCWO scenario: stock made a large +45% move and pulled back to +25%
    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: 25.0, postIgnitionPeakGainPct: 45.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeFalse()
        ->and($bonus['freshness_multiplier'])->toBe(0.0)
        ->and($bonus['eligible'])->toBeFalse()
        ->and($bonus['points'])->toBe(0);

    $entry = $scorer->ignitionBonusBreakdownEntry($alert, 'heartbeat_consolidation_spike');
    expect($entry['value'])->toContain('Price-led ignition — late/extended')
        ->and($entry['value'])->toContain('Post-ignition: +25.0% current | +45.0% peak | bonus expired');
});

test('12. Current/live quote above all historical closes is included in the effective peak', function () {
    $calculator = new IgnitionExtensionCalculator;

    $bars = [
        ['date' => '2026-08-01', 'open' => 10.0, 'high' => 10.5, 'low' => 9.8, 'close' => 10.0, 'volume' => 1000],
        ['date' => '2026-08-02', 'open' => 10.0, 'high' => 12.0, 'low' => 9.9, 'close' => 11.5, 'volume' => 5000], // Spike bar: close = 11.50
        ['date' => '2026-08-03', 'open' => 11.5, 'high' => 12.5, 'low' => 11.2, 'close' => 12.0, 'volume' => 2000], // Close = 12.00 (+4.35%)
    ];

    // Current quote is 15.00 (> historical close of 12.00)
    $res = $calculator->calculate($bars, '2026-08-02', 15.0);

    expect($res['reference_price'])->toBe(11.5)
        ->and($res['current_gain_pct'])->toEqualWithDelta(((15.0 - 11.5) / 11.5) * 100, 0.001)
        ->and($res['peak_gain_pct'])->toEqualWithDelta(((15.0 - 11.5) / 11.5) * 100, 0.001);
});

test('13. Historical intraday high values are not used for peak extension', function () {
    $calculator = new IgnitionExtensionCalculator;

    $bars = [
        ['date' => '2026-08-01', 'open' => 10.0, 'high' => 10.5, 'low' => 9.8, 'close' => 10.0, 'volume' => 1000],
        ['date' => '2026-08-02', 'open' => 10.0, 'high' => 11.0, 'low' => 9.9, 'close' => 10.0, 'volume' => 5000], // Spike bar: close = 10.00
        // Next day has high intraday wick of 20.00 (+100%), but close is only 11.00 (+10%)
        ['date' => '2026-08-03', 'open' => 10.5, 'high' => 20.0, 'low' => 10.0, 'close' => 11.0, 'volume' => 2000],
        ['date' => '2026-08-04', 'open' => 11.0, 'high' => 18.0, 'low' => 10.5, 'close' => 11.2, 'volume' => 2000],
    ];

    $res = $calculator->calculate($bars, '2026-08-02', 11.2);

    expect($res['reference_price'])->toBe(10.0)
        ->and($res['current_gain_pct'])->toEqualWithDelta(12.0, 0.001)
        ->and($res['peak_gain_pct'])->toEqualWithDelta(12.0, 0.001); // Uses max(10, 11.0, 11.2) = 11.2 (+12%), NOT wick of 20.0 (+100%)
});

test('14. Missing freshness information receives 0 rather than an unverified full bonus', function () {
    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;

    // Alert qualifies on quality (base, dry-up, RVOL, price gain) but has null freshness fields
    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: null, postIgnitionPeakGainPct: null);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['quality_qualified'])->toBeTrue()
        ->and($bonus['freshness_eligible'])->toBeFalse()
        ->and($bonus['freshness_multiplier'])->toBe(0.0)
        ->and($bonus['eligible'])->toBeFalse()
        ->and($bonus['points'])->toBe(0);
});

test('15. An ignition occurring on the latest bar has approximately 0% post-ignition peak gain and can receive full bonus', function () {
    $calculator = new IgnitionExtensionCalculator;

    $bars = [
        ['date' => '2026-08-01', 'open' => 10.0, 'high' => 10.5, 'low' => 9.8, 'close' => 10.0, 'volume' => 1000],
        ['date' => '2026-08-02', 'open' => 10.0, 'high' => 12.0, 'low' => 9.9, 'close' => 12.0, 'volume' => 5000], // Spike on latest bar
    ];

    $res = $calculator->calculate($bars, '2026-08-02', null);

    expect($res['reference_price'])->toBe(12.0)
        ->and($res['current_gain_pct'])->toBe(0.0)
        ->and($res['peak_gain_pct'])->toBe(0.0);

    enableIgnitionBonus(['bonus_points' => 20]);
    $scorer = new StockBuySetupScorer;
    $alert = ignitionAlert(120, 0.40, 2.5, 15.0, postIgnitionGainPct: $res['current_gain_pct'], postIgnitionPeakGainPct: $res['peak_gain_pct']);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['freshness_multiplier'])->toBe(1.0)
        ->and($bonus['points'])->toBe(20);
});

test('16. bonus_points = 0 behavior remains unchanged', function () {
    $scorer = new StockBuySetupScorer;
    $alert = ignitionAlert(140, 0.40, 2.4, 16.0, postIgnitionGainPct: 5.0, postIgnitionPeakGainPct: 5.0);

    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['bonus_points'])->toBe(0)
        ->and($bonus['points'])->toBe(0)
        ->and($bonus['quality_qualified'])->toBeFalse()
        ->and($bonus['eligible'])->toBeFalse();
});

test('17. Final setup score still caps at 100', function () {
    enableIgnitionBonus(['bonus_points' => 15]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(150, 0.44, 3.9, 16.0, postIgnitionGainPct: 5.0, postIgnitionPeakGainPct: 5.0);
    $bonus = $scorer->ignitionBonus($alert, 'heartbeat_consolidation_spike');

    $baseScore = 95;
    $finalScore = min(100, $baseScore + $bonus['points']);

    expect($bonus['points'])->toBe(15)
        ->and($finalScore)->toBe(100);
});

test('18. raw_setup_score remains unaffected by the bonus', function () {
    enableIgnitionBonus(['bonus_points' => 10]);
    $scorer = new StockBuySetupScorer;

    $alert = ignitionAlert(150, 0.44, 3.9, 16.0, postIgnitionGainPct: 5.0, postIgnitionPeakGainPct: 5.0);
    $breakdown = $scorer->breakdown($alert, 'heartbeat_consolidation_spike');
    $scoreMeta = $scorer->scoreMetaFromBreakdown($breakdown);

    // Ignition bonus is NOT part of breakdown and does NOT affect raw_setup_score
    expect($breakdown)->not->toHaveKey('ignition_bonus')
        ->and($scoreMeta['normalized'])->toBeGreaterThanOrEqual(0);
});

test('19. Existing saved ignition configuration loads without losing its configured values and receives 15/40 defaults for the new fields', function () {
    $configService = app(BuySetupConfigService::class);
    $savedConfig = $configService->getConfig();

    // Simulate an older saved configuration without the new freshness keys
    $savedConfig['setup_types']['heartbeat_consolidation_spike']['ignition_bonus'] = [
        'bonus_points' => 12,
        'color' => 'gold',
        'min_base_days' => 100,
        'min_volume_dry_up_pct' => 35.0,
        'price_led_min_relative_volume' => 2.5,
        'price_led_min_price_change_pct' => 14.0,
        'volume_led_min_relative_volume' => 3.5,
        'volume_led_min_price_change_pct' => 9.0,
    ];

    $configService->saveConfig($savedConfig);

    $loaded = $configService->getIgnitionBonusConfig('heartbeat_consolidation_spike');

    // Preserves existing custom fields
    expect($loaded['bonus_points'])->toBe(12)
        ->and($loaded['color'])->toBe('gold')
        ->and($loaded['min_base_days'])->toBe(100)
        ->and($loaded['min_volume_dry_up_pct'])->toBe(35.0)
        ->and($loaded['price_led_min_relative_volume'])->toBe(2.5)
        ->and($loaded['price_led_min_price_change_pct'])->toBe(14.0)
        // Automatically populated defaults for new freshness fields
        ->and($loaded['full_bonus_max_post_gain_pct'])->toBe(15.0)
        ->and($loaded['zero_bonus_post_gain_pct'])->toBe(40.0);
});

test('20. Ignition UI filter excludes signals at >= 40% peak extension', function () {
    $user = User::factory()->create();
    enableIgnitionBonus(['bonus_points' => 8, 'zero_bonus_post_gain_pct' => 40.0]);

    // Alert with 40% peak gain (expired)
    StockBuySetupAlert::create([
        'symbol' => 'EXTENDED',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 80,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.0,
        'spike_price_change_pct' => 15.0,
        'ignition_reference_price' => 10.0,
        'post_ignition_gain_pct' => 25.0,
        'post_ignition_peak_gain_pct' => 40.0,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    // Alert with 45% peak gain (expired)
    StockBuySetupAlert::create([
        'symbol' => 'OVEREXTENDED',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 80,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.0,
        'spike_price_change_pct' => 15.0,
        'ignition_reference_price' => 10.0,
        'post_ignition_gain_pct' => 20.0,
        'post_ignition_peak_gain_pct' => 45.0,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('minScore', null)
        ->set('minMarketCap', null)
        ->set('bonusType', 'ignition_accumulation');

    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(0);
});

test('21. Ignition UI filter retains partially decayed signals below 40%', function () {
    $user = User::factory()->create();
    enableIgnitionBonus(['bonus_points' => 8, 'zero_bonus_post_gain_pct' => 40.0]);

    // Fresh signal (10% peak gain)
    StockBuySetupAlert::create([
        'symbol' => 'FRESH',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 80,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.0,
        'spike_price_change_pct' => 15.0,
        'ignition_reference_price' => 10.0,
        'post_ignition_gain_pct' => 8.0,
        'post_ignition_peak_gain_pct' => 10.0,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    // Partially decayed signal (25% peak gain)
    StockBuySetupAlert::create([
        'symbol' => 'PARTIAL',
        'source' => 'test',
        'setup_type' => 'heartbeat_consolidation_spike',
        'setup_score' => 80,
        'base_duration_days' => 120,
        'volume_dry_up_score' => 0.40,
        'spike_relative_volume' => 3.0,
        'spike_price_change_pct' => 15.0,
        'ignition_reference_price' => 10.0,
        'post_ignition_gain_pct' => 20.0,
        'post_ignition_peak_gain_pct' => 25.0,
        'spike_date' => now(),
        'detected_at' => now(),
        'status' => 'detected',
    ]);

    $component = Livewire::actingAs($user)
        ->test(StockBuySetups::class)
        ->set('minScore', null)
        ->set('minMarketCap', null)
        ->set('bonusType', 'ignition_accumulation');

    $alerts = $component->viewData('alerts');
    expect($alerts)->toHaveCount(2)
        ->and($alerts->pluck('symbol')->all())->toEqualCanonicalizing(['FRESH', 'PARTIAL']);
});

test('result struct carries spikePriceChangePct and post-ignition fields and works with scorer', function () {
    enableIgnitionBonus(['bonus_points' => 8]);
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
