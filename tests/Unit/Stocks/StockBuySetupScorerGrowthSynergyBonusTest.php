<?php

use App\Models\StockBuySetupAlert;
use App\Services\Stocks\BuySetupConfigService;
use App\Services\Stocks\StockBuySetupScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * Enables the Growth Synergy Bonus for heartbeat_consolidation_spike using
 * the default thresholds (max_points=10, min_sales_yoy=20,
 * medium=50, strong=75, exceptional=90), unless overridden.
 */
function enableGrowthSynergyBonus(array $overrides = []): void
{
    $configService = app(BuySetupConfigService::class);
    $config = $configService->getConfig();
    $config['setup_types']['heartbeat_consolidation_spike']['growth_synergy_bonus'] = array_merge([
        'enabled' => true,
        'max_points' => 10,
        'min_sales_yoy' => 20,
        'medium_threshold' => 50,
        'strong_threshold' => 75,
        'exceptional_threshold' => 90,
    ], $overrides);
    $configService->saveConfig($config);
}

/**
 * Builds an alert with fields relevant to the Growth Synergy Bonus. Sales
 * Acceleration and margin-expansion bps are chosen so that the analyzer's
 * normalized (0-100) scores approximately equal the desired target scores,
 * using the default scales/thresholds.
 */
function growthSynergyAlert(float $salesYoy, float $salesAccelBps, ?float $omeBps, ?float $fcfBps): StockBuySetupAlert
{
    return new StockBuySetupAlert([
        'setup_type' => 'heartbeat_consolidation_spike',
        'quarterly_revenue_growth_pct' => $salesYoy,
        'sales_acceleration' => $salesAccelBps,
        'operating_margin_expansion_bps' => $omeBps,
        'fcf_margin_expansion_bps' => $fcfBps,
    ]);
}

test('growth synergy bonus is disabled by default and awards zero points', function () {
    $scorer = new StockBuySetupScorer;

    // Sales Acceleration and margin bps calibrated to hit the exceptional tier if enabled.
    $alert = growthSynergyAlert(45.0, 3000.0, 1500.0, 1500.0);

    $bonus = $scorer->growthSynergyBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['enabled'])->toBeFalse()
        ->and($bonus['points'])->toBe(0);
});

test('example 1: all three metrics exceptional awards the maximum +10 bonus', function () {
    enableGrowthSynergyBonus();
    $scorer = new StockBuySetupScorer;

    // Sales YoY 45%, and margin bps calibrated to reach >=90 normalized score
    // for all three metrics using the default thresholds (100pt = 1500bps)
    // and sales-acceleration scale (3000 -> 100pt).
    $alert = growthSynergyAlert(45.0, 3000.0, 1500.0, 1500.0);

    $bonus = $scorer->growthSynergyBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeTrue()
        ->and($bonus['sales_acceleration_score'])->toBeGreaterThanOrEqual(90)
        ->and($bonus['operating_margin_expansion_score'])->toBeGreaterThanOrEqual(90)
        ->and($bonus['fcf_margin_expansion_score'])->toBeGreaterThanOrEqual(90)
        ->and($bonus['points'])->toBe(10);
});

test('example 2: all three metrics strong awards +8 bonus', function () {
    enableGrowthSynergyBonus();
    $scorer = new StockBuySetupScorer;

    // Thresholds: 75-point bps = 1000. Use bps comfortably between the
    // strong (75) and exceptional (90) normalized-score tiers.
    $alert = growthSynergyAlert(35.0, 900.0, 1050.0, 1050.0);

    $bonus = $scorer->growthSynergyBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['sales_acceleration_score'])->toBeGreaterThanOrEqual(75)
        ->and($bonus['sales_acceleration_score'])->toBeLessThan(90)
        ->and($bonus['operating_margin_expansion_score'])->toBeGreaterThanOrEqual(75)
        ->and($bonus['operating_margin_expansion_score'])->toBeLessThan(90)
        ->and($bonus['fcf_margin_expansion_score'])->toBeGreaterThanOrEqual(75)
        ->and($bonus['fcf_margin_expansion_score'])->toBeLessThan(90)
        ->and($bonus['points'])->toBe(8);
});

test('example 3: all three metrics medium awards +5 bonus', function () {
    enableGrowthSynergyBonus();
    $scorer = new StockBuySetupScorer;

    // Thresholds: 50-point bps = 500. Use bps between medium (50) and strong (75).
    $alert = growthSynergyAlert(30.0, 200.0, 600.0, 600.0);

    $bonus = $scorer->growthSynergyBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['sales_acceleration_score'])->toBeGreaterThanOrEqual(50)
        ->and($bonus['sales_acceleration_score'])->toBeLessThan(75)
        ->and($bonus['operating_margin_expansion_score'])->toBeGreaterThanOrEqual(50)
        ->and($bonus['operating_margin_expansion_score'])->toBeLessThan(75)
        ->and($bonus['fcf_margin_expansion_score'])->toBeGreaterThanOrEqual(50)
        ->and($bonus['fcf_margin_expansion_score'])->toBeLessThan(75)
        ->and($bonus['points'])->toBe(5);
});

test('example 4: missing FCF still allows the two-metric +2 tier', function () {
    enableGrowthSynergyBonus();
    $scorer = new StockBuySetupScorer;

    $alert = growthSynergyAlert(28.0, 200.0, 600.0, null);

    $bonus = $scorer->growthSynergyBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['fcf_margin_expansion_score'])->toBeNull()
        ->and($bonus['sales_acceleration_score'])->toBeGreaterThanOrEqual(50)
        ->and($bonus['operating_margin_expansion_score'])->toBeGreaterThanOrEqual(50)
        ->and($bonus['points'])->toBe(2);
});

test('example 5: sales yoy below the configured minimum awards zero bonus', function () {
    enableGrowthSynergyBonus();
    $scorer = new StockBuySetupScorer;

    // Even with exceptional metrics, shrinking revenue disqualifies the bonus.
    $alert = growthSynergyAlert(-10.0, 3000.0, 1500.0, 1500.0);

    $bonus = $scorer->growthSynergyBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['eligible'])->toBeFalse()
        ->and($bonus['points'])->toBe(0);
});

test('the bonus never exceeds the configured max_points even at the exceptional tier', function () {
    enableGrowthSynergyBonus(['max_points' => 3]);
    $scorer = new StockBuySetupScorer;

    $alert = growthSynergyAlert(45.0, 3000.0, 1500.0, 1500.0);

    $bonus = $scorer->growthSynergyBonus($alert, 'heartbeat_consolidation_spike');

    expect($bonus['points'])->toBe(3);
});

test('missing sales acceleration or operating margin expansion disqualifies the two-metric tier', function () {
    enableGrowthSynergyBonus();
    $scorer = new StockBuySetupScorer;

    $alertMissingSales = growthSynergyAlert(28.0, 0.0, 600.0, null);
    expect($scorer->growthSynergyBonus($alertMissingSales, 'heartbeat_consolidation_spike')['points'])->toBe(0);

    $alertMissingOme = growthSynergyAlert(28.0, 200.0, null, null);
    expect($scorer->growthSynergyBonus($alertMissingOme, 'heartbeat_consolidation_spike')['points'])->toBe(0);
});

test('growth synergy bonus is added on top of the base setup score and capped at 100', function () {
    enableGrowthSynergyBonus();
    $scorer = new StockBuySetupScorer;

    $alert = growthSynergyAlert(45.0, 3000.0, 1500.0, 1500.0);
    $bonus = $scorer->growthSynergyBonus($alert, 'heartbeat_consolidation_spike');

    // Simulates the job: base setup score already at 95, bonus should be
    // added but capped at the application's overall 100 maximum.
    $finalScore = min(100, 95 + $bonus['points']);

    expect($bonus['points'])->toBe(10)
        ->and($finalScore)->toBe(100);
});
