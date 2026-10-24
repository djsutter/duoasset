<?php

use App\Models\EarningsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('formats report_date canonically as Y-m-d and retrieves as Carbon', function () {
    $event = EarningsEvent::create([
        'source' => 'fmp',
        'symbol' => 'AAPL',
        'report_date' => '2026-10-24 15:30:00',
        'detected_at' => now(),
    ]);

    // DB row has canonical 'Y-m-d'
    $rawDate = $event->getRawOriginal('report_date');
    expect($rawDate)->toBe('2026-10-24');

    // Accessor returns Carbon instance
    expect($event->report_date)->toBeInstanceOf(Carbon::class);
    expect($event->report_date->toDateString())->toBe('2026-10-24');
});

it('supports idempotent updateOrCreate without SQLite unique constraint violations', function () {
    $first = EarningsEvent::updateOrCreate(
        [
            'source' => 'fmp',
            'symbol' => 'MSFT',
            'report_date' => '2026-10-24',
        ],
        [
            'eps_actual' => 1.50,
            'detected_at' => now(),
        ]
    );

    expect($first->id)->toBeGreaterThan(0);

    // Second updateOrCreate with same key should update the existing row, not insert a duplicate
    $second = EarningsEvent::updateOrCreate(
        [
            'source' => 'fmp',
            'symbol' => 'MSFT',
            'report_date' => '2026-10-24',
        ],
        [
            'eps_actual' => 1.75,
        ]
    );

    expect($second->id)->toBe($first->id);
    expect((float) $second->eps_actual)->toBe(1.75);
    expect(EarningsEvent::where('symbol', 'MSFT')->count())->toBe(1);
});
