<?php

use App\Models\SectorFlowSnapshot;
use App\Services\MoneyFlow\SectorMoneyFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

// fakeMoneyFlowFmp() / moneyFlowDailySeries() / moneyFlowIntradaySeries()
// live in tests/Support/money_flow_fakes.php (loaded via tests/Pest.php).

it('captures all 11 sectors in one end-of-day pass', function () {
    fakeMoneyFlowFmp();

    $summary = app(SectorMoneyFlowService::class)->capture(
        SectorFlowSnapshot::INTERVAL_EOD,
        null,
        CarbonImmutable::parse('2026-07-17 16:30:00'),
    );

    expect($summary->publishedCount())->toBe(11);
    expect($summary->skipped)->toBe([]);
    expect(SectorFlowSnapshot::count())->toBe(11);

    // snapshot_date follows the latest available session, not the calendar.
    expect($summary->snapshotDate)->toBe('2026-07-17');

    $tech = SectorFlowSnapshot::where('sector', 'technology')->first();
    expect($tech->strength)->not->toBeNull();
    expect($tech->daily_score)->not->toBeNull();
    expect($tech->interval)->toBe('eod');
    expect($tech->captured_slot)->toBe('eod');
    expect($tech->etf_count)->toBe(5);
    expect($tech->constituents)->toHaveKey('XLK');
    expect($tech->direction)->not->toBeNull();
});

it('assigns a unique cross-sectional rank across published sectors', function () {
    // Give each sector a distinct trajectory so strengths differ and ranks
    // are unique. We do this by letting the generic series stand and asserting
    // the rank set covers 1..11.
    fakeMoneyFlowFmp();

    app(SectorMoneyFlowService::class)->capture(
        SectorFlowSnapshot::INTERVAL_EOD,
        null,
        CarbonImmutable::parse('2026-07-17 16:30:00'),
    );

    $ranks = SectorFlowSnapshot::orderBy('rank')->pluck('rank')->all();
    expect($ranks)->toBe(range(1, 11));

    $top = SectorFlowSnapshot::where('rank', 1)->first();
    expect((float) $top->percentile_rank)->toBe(100.0);
});

it('leaves velocity null on the first capture and populates it on the next', function () {
    fakeMoneyFlowFmp();
    $service = app(SectorMoneyFlowService::class);

    $service->capture(SectorFlowSnapshot::INTERVAL_EOD, ['technology'], CarbonImmutable::parse('2026-07-16 16:30:00'));
    $first = SectorFlowSnapshot::where('sector', 'technology')->latest('captured_at')->first();
    expect($first->daily_velocity)->toBeNull();
    expect($first->velocity)->toBeNull();

    $service->capture(SectorFlowSnapshot::INTERVAL_EOD, ['technology'], CarbonImmutable::parse('2026-07-17 16:30:00'));
    $second = SectorFlowSnapshot::where('sector', 'technology')
        ->where('snapshot_date', '2026-07-17')->first();
    expect($second->daily_velocity)->not->toBeNull();
    // Acceleration still unavailable: the prior snapshot had no velocity yet.
    expect($second->daily_acceleration)->toBeNull();
});

it('populates acceleration only from the third capture onward', function () {
    fakeMoneyFlowFmp();
    $service = app(SectorMoneyFlowService::class);

    foreach (['2026-07-15', '2026-07-16', '2026-07-17'] as $day) {
        $service->capture(SectorFlowSnapshot::INTERVAL_EOD, ['technology'], CarbonImmutable::parse("$day 16:30:00"));
    }

    $third = SectorFlowSnapshot::where('sector', 'technology')
        ->orderByDesc('captured_at')->first();
    expect($third->daily_velocity)->not->toBeNull();
    expect($third->daily_acceleration)->not->toBeNull();
});

it('skips a sector that has fewer than the minimum valid ETFs', function () {
    // Wipe out 3 of Technology's 5 ETFs -> only 2 valid, below the floor of 3.
    fakeMoneyFlowFmp(emptyFor: ['XLK', 'VGT', 'IYW']);

    $summary = app(SectorMoneyFlowService::class)->capture(
        SectorFlowSnapshot::INTERVAL_EOD,
        ['technology', 'energy'],
        CarbonImmutable::parse('2026-07-17 16:30:00'),
    );

    expect($summary->skipped)->toHaveKey('technology');
    expect($summary->publishedSectors)->toContain('energy');
    expect(SectorFlowSnapshot::where('sector', 'technology')->exists())->toBeFalse();
    expect(SectorFlowSnapshot::where('sector', 'energy')->exists())->toBeTrue();
});

it('still publishes with a null relative strength when the benchmark is missing', function () {
    fakeMoneyFlowFmp(emptyFor: ['SPY']);

    $summary = app(SectorMoneyFlowService::class)->capture(
        SectorFlowSnapshot::INTERVAL_EOD,
        ['technology'],
        CarbonImmutable::parse('2026-07-17 16:30:00'),
    );

    expect($summary->publishedSectors)->toContain('technology');
    $tech = SectorFlowSnapshot::where('sector', 'technology')->first();
    expect($tech->daily_relative_strength)->toBeNull();
    expect($tech->daily_score)->not->toBeNull(); // change/volume still score it
});

it('is idempotent for the same sector, date and slot', function () {
    fakeMoneyFlowFmp();
    $service = app(SectorMoneyFlowService::class);
    $asOf = CarbonImmutable::parse('2026-07-17 16:30:00');

    $service->capture(SectorFlowSnapshot::INTERVAL_EOD, ['technology'], $asOf);
    $service->capture(SectorFlowSnapshot::INTERVAL_EOD, ['technology'], $asOf);

    expect(SectorFlowSnapshot::where('sector', 'technology')->count())->toBe(1);
});

it('records an hourly capture under the market-hour slot', function () {
    fakeMoneyFlowFmp();

    app(SectorMoneyFlowService::class)->capture(
        SectorFlowSnapshot::INTERVAL_HOURLY,
        ['technology'],
        CarbonImmutable::parse('2026-07-17 11:00:00', 'America/New_York'),
    );

    $snap = SectorFlowSnapshot::where('sector', 'technology')->first();
    expect($snap->interval)->toBe('hourly');
    expect($snap->captured_slot)->toBe('11');
    expect($snap->hourly_score)->not->toBeNull();
});
