<?php

use App\Services\Dashboard\TradingSessionService;
use Carbon\CarbonImmutable;

afterEach(fn () => CarbonImmutable::setTestNow());

function at(string $localTime, string $tz = 'America/New_York'): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($localTime, $tz));
}

test('weekday session boundaries are open from 09:30 inclusive to 16:00 exclusive', function (string $time, bool $open) {
    at($time);

    expect((new TradingSessionService)->isTradingHours('America/New_York'))->toBe($open);
})->with([
    'before open' => ['2026-10-28 09:29:59', false],
    'at open' => ['2026-10-28 09:30:00', true],
    'midday' => ['2026-10-28 12:00:00', true],
    'last second' => ['2026-10-28 15:59:59', true],
    'at close' => ['2026-10-28 16:00:00', false],
    'early morning' => ['2026-10-28 05:00:00', false],
    'evening' => ['2026-10-28 21:00:00', false],
]);

test('weekends are never trading hours', function () {
    at('2026-10-31 11:00:00'); // Saturday
    expect((new TradingSessionService)->isTradingHours('America/New_York'))->toBeFalse();

    at('2026-11-01 11:00:00'); // Sunday
    expect((new TradingSessionService)->isTradingHours('America/New_York'))->toBeFalse();
});

test('regional timezones are evaluated locally', function () {
    // 14:00 UTC Wednesday = 10:00 New York (open), 23:00 Tokyo (closed), 14:00 London (open)
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 14:00:00', 'UTC'));
    $svc = new TradingSessionService;

    expect($svc->isTradingHours('America/New_York'))->toBeTrue()
        ->and($svc->isTradingHours('Asia/Tokyo'))->toBeFalse()
        ->and($svc->isTradingHours('Europe/London'))->toBeTrue();
});

test('invalid timezone falls back to America/New_York', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 15:00:00', 'UTC')); // 11:00 NY

    expect((new TradingSessionService)->isTradingHours('Not/AZone'))->toBeTrue();
});

test('seconds until next market open covers each situation', function (string $time, int $expected) {
    at($time);

    expect((new TradingSessionService)->secondsUntilNextMarketOpen('America/New_York'))->toBe($expected);
})->with([
    'early morning same day' => ['2026-10-28 08:30:00', 3600],
    'during session rolls to next day' => ['2026-10-28 15:30:00', 18 * 3600],
    'evening to next morning' => ['2026-10-28 20:00:00', 13 * 3600 + 30 * 60],
    'friday evening to monday' => ['2026-10-23 18:00:00', (3 * 24 - 18 + 9) * 3600 + 30 * 60],
    'saturday to monday' => ['2026-10-24 09:30:00', 2 * 24 * 3600],
    'sunday to monday' => ['2026-10-25 12:00:00', 21 * 3600 + 30 * 60],
]);

test('ttl is the active ttl during session and time-to-open outside', function () {
    $svc = new TradingSessionService;

    at('2026-10-28 11:00:00');
    expect($svc->getTradingHoursTtl('America/New_York'))->toBe(900)
        ->and($svc->getTradingHoursTtl('America/New_York', 300))->toBe(300);

    at('2026-10-28 20:00:00');
    expect($svc->getTradingHoursTtl('America/New_York'))->toBe(13 * 3600 + 30 * 60);
});
