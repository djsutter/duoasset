<?php

use App\Models\SectorFlowSnapshot;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

it('captures and persists all sectors on a default eod run', function () {
    fakeMoneyFlowFmp();

    $this->artisan('moneyflow:update')
        ->assertSuccessful()
        ->expectsOutputToContain('Published 11 sector(s)');

    expect(SectorFlowSnapshot::count())->toBe(11);
    expect(SectorFlowSnapshot::where('interval', 'eod')->count())->toBe(11);
});

it('honours the disabled gate unless forced', function () {
    fakeMoneyFlowFmp();
    config()->set('market_data.moneyflow.enabled', false);

    $this->artisan('moneyflow:update')
        ->assertSuccessful()
        ->expectsOutputToContain('disabled');
    expect(SectorFlowSnapshot::count())->toBe(0);

    $this->artisan('moneyflow:update --force')->assertSuccessful();
    expect(SectorFlowSnapshot::count())->toBe(11);
});

it('captures an intraday hourly run', function () {
    fakeMoneyFlowFmp();

    $this->artisan('moneyflow:update', ['--interval' => 'hourly', '--sector' => ['technology']])
        ->assertSuccessful();

    $snap = SectorFlowSnapshot::where('sector', 'technology')->first();
    expect($snap->interval)->toBe('hourly');
    expect($snap->hourly_score)->not->toBeNull();
});

it('restricts to the requested sectors', function () {
    fakeMoneyFlowFmp();

    $this->artisan('moneyflow:update', ['--sector' => ['technology', 'energy']])
        ->assertSuccessful();

    expect(SectorFlowSnapshot::count())->toBe(2);
    expect(SectorFlowSnapshot::pluck('sector')->sort()->values()->all())->toBe(['energy', 'technology']);
});

it('rejects an invalid interval', function () {
    fakeMoneyFlowFmp();

    $this->artisan('moneyflow:update', ['--interval' => 'weekly'])
        ->assertExitCode(2); // Command::INVALID
});

it('rejects an unknown sector key', function () {
    fakeMoneyFlowFmp();

    $this->artisan('moneyflow:update', ['--sector' => ['crypto']])
        ->assertExitCode(2);

    expect(SectorFlowSnapshot::count())->toBe(0);
});

it('fails when no sector can be published', function () {
    // Technology loses 3 of 5 ETFs -> below the min; nothing publishable.
    fakeMoneyFlowFmp(emptyFor: ['XLK', 'VGT', 'IYW']);

    $this->artisan('moneyflow:update', ['--sector' => ['technology']])
        ->assertFailed();

    expect(SectorFlowSnapshot::count())->toBe(0);
});
