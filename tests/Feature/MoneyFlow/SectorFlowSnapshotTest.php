<?php

use App\Models\SectorFlowSnapshot;
use Illuminate\Database\QueryException;

/**
 * Phase 1: the sector_flow_snapshots table + model behave correctly —
 * casts, one-snapshot-per-(sector, trading-date) uniqueness, idempotent
 * updates, the constituents JSON round-trip, and the latestPerSector scope.
 */
it('persists a snapshot and casts JSON, dates and decimals', function () {
    $snap = SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-17')->create([
        'strength' => 82.5,
        'etf_count' => 5,
        'constituents' => [
            'XLK' => ['issuer' => 'spdr', 'weight' => 1.0, 'daily_change_pct' => 1.23, 'error' => null],
        ],
    ]);

    $fresh = $snap->fresh();

    expect($fresh->constituents)->toBeArray();
    expect($fresh->constituents['XLK']['issuer'])->toBe('spdr');
    expect($fresh->constituents['XLK']['error'])->toBeNull();
    expect($fresh->snapshot_date->toDateString())->toBe('2026-07-17');
    expect((float) $fresh->strength)->toBe(82.5);
    expect($fresh->etf_count)->toBe(5);
});

it('allows null velocity and acceleration (first snapshot per sector)', function () {
    $snap = SectorFlowSnapshot::factory()->forSectorDate('energy', '2026-07-17')->create([
        'velocity' => null,
        'acceleration' => null,
        'daily_velocity' => null,
        'daily_acceleration' => null,
    ]);

    $fresh = $snap->fresh();

    expect($fresh->velocity)->toBeNull();
    expect($fresh->acceleration)->toBeNull();
});

it('updates in place for the same (sector, snapshot_date) via updateOrCreate', function () {
    SectorFlowSnapshot::updateOrCreate(
        ['sector' => 'energy', 'snapshot_date' => '2026-07-17'],
        ['strength' => 50, 'etf_count' => 5, 'captured_at' => now()],
    );
    SectorFlowSnapshot::updateOrCreate(
        ['sector' => 'energy', 'snapshot_date' => '2026-07-17'],
        ['strength' => 75, 'etf_count' => 4, 'captured_at' => now()],
    );

    $rows = SectorFlowSnapshot::where('sector', 'energy')->where('snapshot_date', '2026-07-17')->get();

    expect($rows)->toHaveCount(1);
    expect((float) $rows->first()->strength)->toBe(75.0);
    expect($rows->first()->etf_count)->toBe(4);
});

it('rejects a duplicate (sector, snapshot_date) at the database level', function () {
    SectorFlowSnapshot::factory()->forSectorDate('materials', '2026-07-17')->create();
    SectorFlowSnapshot::factory()->forSectorDate('materials', '2026-07-17')->create();
})->throws(QueryException::class);

it('allows the same sector on different trading dates', function () {
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-16')->create();
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-17')->create();

    expect(SectorFlowSnapshot::where('sector', 'technology')->count())->toBe(2);
});

it('lets an hourly and an eod capture coexist on the same sector/date, latest wins', function () {
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-17')->hourly('10')
        ->create(['captured_at' => now()->setTime(10, 0), 'strength' => 50]);
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-17')
        ->create(['captured_at' => now()->setTime(16, 30), 'strength' => 70]);

    expect(SectorFlowSnapshot::where('sector', 'technology')->count())->toBe(2);

    $latest = SectorFlowSnapshot::query()->latestPerSector()->get();
    expect($latest)->toHaveCount(1);
    expect((float) $latest->first()->strength)->toBe(70.0);
    expect($latest->first()->interval)->toBe(SectorFlowSnapshot::INTERVAL_EOD);
});

it('latestPerSector returns the newest snapshot for each sector', function () {
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-15')
        ->create(['strength' => 10, 'captured_at' => now()->subDays(2)]);
    SectorFlowSnapshot::factory()->forSectorDate('technology', '2026-07-17')
        ->create(['strength' => 90, 'captured_at' => now()]);
    SectorFlowSnapshot::factory()->forSectorDate('energy', '2026-07-16')
        ->create(['strength' => 40, 'captured_at' => now()->subDay()]);

    $latest = SectorFlowSnapshot::query()->latestPerSector()->get();

    expect($latest)->toHaveCount(2);

    $tech = $latest->firstWhere('sector', 'technology');
    expect($tech->snapshot_date->toDateString())->toBe('2026-07-17');
    expect((float) $tech->strength)->toBe(90.0);

    $energy = $latest->firstWhere('sector', 'energy');
    expect($energy->snapshot_date->toDateString())->toBe('2026-07-16');
});
