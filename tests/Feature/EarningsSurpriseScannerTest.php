<?php

use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Services\Earnings\EarningsSurpriseScorer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Helper: register HTTP fakes for FMP endpoints with provided rows.
 */
function fakeFmp(array $surprises = [], array $calendar = [], array $profiles = [], array $quotes = []): void
{
    Http::fake(function ($request) use ($surprises, $calendar, $profiles, $quotes) {
        $url = (string) $request->url();

        if (str_contains($url, '/earnings-surprises')) {
            return Http::response($surprises, 200);
        }
        if (str_contains($url, '/earnings-calendar')) {
            return Http::response($calendar, 200);
        }
        if (str_contains($url, '/profile')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
            $sym = $q['symbol'] ?? null;

            return Http::response($sym && isset($profiles[$sym]) ? [$profiles[$sym]] : [], 200);
        }
        if (str_contains($url, '/quote')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
            $sym = $q['symbol'] ?? null;

            return Http::response($sym && isset($quotes[$sym]) ? [$quotes[$sym]] : [], 200);
        }

        return Http::response([], 200);
    });
}

beforeEach(function () {
    config([
        'market_data.provider' => 'fmp',
        'market_data.fmp.api_key' => 'test-key',
        'market_data.fmp.base_url' => 'https://test.local/stable',
        'market_data.earnings_scanner.enabled' => true,
        'market_data.earnings_scanner.min_market_cap' => 100_000_000,
        'market_data.earnings_scanner.min_eps_surprise_percent' => 90,
        'market_data.earnings_scanner.exchanges' => ['NYSE', 'NASDAQ', 'TSX', 'TSXV'],
    ]);
});

it('calculates EPS surprise percent correctly', function () {
    expect(EarningsSurpriseScorer::calculateSurprisePercent(0.50, 0.25))->toBe(100.0);
    expect(EarningsSurpriseScorer::calculateSurprisePercent(0.10, 0.05))->toBe(100.0);
    expect(round(EarningsSurpriseScorer::calculateSurprisePercent(0.94, 0.50), 4))->toBe(88.0);
});

it('returns null when estimated EPS is zero', function () {
    expect(EarningsSurpriseScorer::calculateSurprisePercent(0.50, 0.0))->toBeNull();
    expect(EarningsSurpriseScorer::calculateSurprisePercent(null, 1.0))->toBeNull();
});

it('creates an alert when EPS surprise >= 90 and market cap >= 100M', function () {
    Notification::fake();

    fakeFmp(
        surprises: [[
            'symbol' => 'BOOM',
            'date' => now()->toDateString(),
            'epsEstimated' => 0.10,
            'epsActual' => 0.50,
            'surprise' => 0.40,
            'surprisePercentage' => 400.0,
        ]],
        profiles: [
            'BOOM' => [
                'symbol' => 'BOOM',
                'companyName' => 'BoomCo',
                'exchangeShortName' => 'NASDAQ',
                'mktCap' => 5_000_000_000,
                'currency' => 'USD',
            ],
        ],
        quotes: [
            'BOOM' => [
                'symbol' => 'BOOM',
                'price' => 50.0,
                'volume' => 1_000_000,
                'avgVolume' => 200_000,
                'marketCap' => 5_000_000_000,
                'exchange' => 'NASDAQ',
                'name' => 'BoomCo',
            ],
        ],
    );

    $this->artisan('earnings:scan-surprises')->assertSuccessful();

    $event = EarningsEvent::where('symbol', 'BOOM')->first();
    expect($event)->not->toBeNull();
    expect((float) $event->eps_surprise_percent)->toBe(400.0);
    expect($event->market_cap)->toBe(5_000_000_000);
    expect($event->alert)->not->toBeNull();
    expect($event->alert->score)->toBeGreaterThanOrEqual(40);
});

it('does not alert when market cap below 100M', function () {
    fakeFmp(
        surprises: [[
            'symbol' => 'SMALL',
            'date' => now()->toDateString(),
            'epsEstimated' => 0.10,
            'epsActual' => 0.50,
            'surprisePercentage' => 400.0,
        ]],
        profiles: [
            'SMALL' => [
                'symbol' => 'SMALL',
                'companyName' => 'SmallCo',
                'exchangeShortName' => 'NASDAQ',
                'mktCap' => 50_000_000,
                'currency' => 'USD',
            ],
        ],
        quotes: [
            'SMALL' => [
                'symbol' => 'SMALL',
                'marketCap' => 50_000_000,
                'exchange' => 'NASDAQ',
            ],
        ],
    );

    $this->artisan('earnings:scan-surprises')->assertSuccessful();

    expect(EarningsAlert::count())->toBe(0);
});

it('does not alert when EPS surprise below threshold', function () {
    fakeFmp(
        surprises: [[
            'symbol' => 'MEH',
            'date' => now()->toDateString(),
            'epsEstimated' => 1.00,
            'epsActual' => 1.10,
            'surprisePercentage' => 10.0,
        ]],
        profiles: [
            'MEH' => [
                'symbol' => 'MEH',
                'exchangeShortName' => 'NYSE',
                'mktCap' => 10_000_000_000,
                'currency' => 'USD',
            ],
        ],
        quotes: ['MEH' => ['symbol' => 'MEH', 'marketCap' => 10_000_000_000, 'exchange' => 'NYSE']],
    );

    $this->artisan('earnings:scan-surprises')->assertSuccessful();

    // Below the lower storage bound (50%) → not even stored.
    expect(EarningsEvent::count())->toBe(0);
    expect(EarningsAlert::count())->toBe(0);
});

it('skips events on exchanges outside the configured list', function () {
    fakeFmp(
        surprises: [[
            'symbol' => 'OTHER',
            'date' => now()->toDateString(),
            'epsEstimated' => 0.10,
            'epsActual' => 0.50,
            'surprisePercentage' => 400.0,
        ]],
        profiles: [
            'OTHER' => [
                'symbol' => 'OTHER',
                'exchangeShortName' => 'LSE',
                'mktCap' => 5_000_000_000,
                'currency' => 'GBP',
            ],
        ],
        quotes: ['OTHER' => ['symbol' => 'OTHER', 'marketCap' => 5_000_000_000, 'exchange' => 'LSE']],
    );

    $this->artisan('earnings:scan-surprises')->assertSuccessful();

    // Stored as event (passes threshold), but no alert created.
    expect(EarningsEvent::where('symbol', 'OTHER')->exists())->toBeTrue();
    expect(EarningsAlert::count())->toBe(0);
});

it('does not create duplicate alerts on repeated scans', function () {
    fakeFmp(
        surprises: [[
            'symbol' => 'DUP',
            'date' => now()->toDateString(),
            'epsEstimated' => 0.10,
            'epsActual' => 0.50,
            'surprisePercentage' => 400.0,
        ]],
        profiles: [
            'DUP' => [
                'symbol' => 'DUP',
                'exchangeShortName' => 'NASDAQ',
                'mktCap' => 5_000_000_000,
                'currency' => 'USD',
            ],
        ],
        quotes: ['DUP' => ['symbol' => 'DUP', 'marketCap' => 5_000_000_000, 'exchange' => 'NASDAQ']],
    );

    $this->artisan('earnings:scan-surprises')->assertSuccessful();
    $this->artisan('earnings:scan-surprises')->assertSuccessful();

    expect(EarningsEvent::where('symbol', 'DUP')->count())->toBe(1);
    expect(EarningsAlert::where('symbol', 'DUP')->count())->toBe(1);
});

it('scorer awards points proportional to surprise size and fundamentals', function () {
    $event = EarningsEvent::create([
        'symbol' => 'SCORE',
        'report_date' => now()->toDateString(),
        'eps_estimated' => 0.10,
        'eps_actual' => 0.50,
        'eps_surprise_percent' => 400,
        'market_cap' => 5_000_000_000,
        'revenue_surprise_percent' => 8,
        'relative_volume' => 3.0,
        'source' => 'fmp',
        'detected_at' => now(),
    ]);

    // >=88 +40, >=150 +15, >=300 +15, mcap>=100M +10, mcap>=1B +10,
    // rev>0 +10, rev>=5 +10, relvol>=2 +10 = 120
    expect((new EarningsSurpriseScorer)->score($event))->toBe(120);
});
