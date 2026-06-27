<?php

use App\Services\MarketData\FmpMarketDataProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Proves FmpMarketDataProvider::quote() composes its return value from three
 * upstream endpoints:
 *   - price + volume + avg_volume + daily_change  -> /quote
 *   - daily_change_percent                        -> /stock-price-change (fallback)
 *   - market_cap + exchange + company_name        -> /profile (fallback)
 */
beforeEach(function () {
    Cache::flush();
});

function fakeFmpComposite(array $quote = [], array $priceChange = [], array $profile = []): void
{
    Http::fake(function ($request) use ($quote, $priceChange, $profile) {
        $url = (string) $request->url();

        if (str_contains($url, '/stock-price-change')) {
            return Http::response($priceChange ? [$priceChange] : [], 200);
        }
        if (str_contains($url, '/profile')) {
            return Http::response($profile ? [$profile] : [], 200);
        }
        if (str_contains($url, '/quote')) {
            return Http::response($quote ? [$quote] : [], 200);
        }

        return Http::response([], 200);
    });
}

it('composes quote from /quote, /stock-price-change, and /profile', function () {
    fakeFmpComposite(
        quote: [
            'symbol' => 'AAPL',
            'price' => 187.32,
            'volume' => 55_000_000,
            'avgVolume' => 60_000_000,
            'change' => 1.18,
            // No changesPercentage here on purpose — must fall through to stock-price-change.
        ],
        priceChange: [
            'symbol' => 'AAPL',
            '1D' => 0.63,
        ],
        profile: [
            'symbol' => 'AAPL',
            'companyName' => 'Apple Inc.',
            'exchangeShortName' => 'NASDAQ',
            'mktCap' => 2_900_000_000_000,
        ],
    );

    $fmp = new FmpMarketDataProvider('https://test.local/stable', 'test-key');
    $result = $fmp->quote('AAPL');

    expect($result)->toBeArray();
    expect($result['price'])->toBe(187.32);
    expect($result['volume'])->toBe(55_000_000);
    expect($result['avg_volume'])->toBe(60_000_000);
    expect($result['daily_change'])->toBe(1.18);
    expect($result['daily_change_percent'])->toBe(0.63);
    expect($result['market_cap'])->toBe(2_900_000_000_000);
    expect($result['exchange'])->toBe('NASDAQ');
    expect($result['company_name'])->toBe('Apple Inc.');
});

it('prefers /quote changesPercentage when present over /stock-price-change', function () {
    fakeFmpComposite(
        quote: [
            'symbol' => 'MSFT',
            'price' => 420.10,
            'changesPercentage' => 1.42,
        ],
        priceChange: [
            'symbol' => 'MSFT',
            '1D' => 9.99, // should be ignored
        ],
        profile: [
            'symbol' => 'MSFT',
            'mktCap' => 3_100_000_000_000,
        ],
    );

    $fmp = new FmpMarketDataProvider('https://test.local/stable', 'test-key');
    $result = $fmp->quote('MSFT');

    expect($result['daily_change_percent'])->toBe(1.42);
    expect($result['market_cap'])->toBe(3_100_000_000_000);
});

it('falls back to /profile price+volume+change when /quote is 402-gated', function () {
    // Reproduces the real-world plan-gated case: /quote and /stock-price-change
    // both return 402, but /profile still yields a usable price snapshot.
    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, '/profile')) {
            return Http::response([[
                'symbol' => 'GOLD',
                'price' => 41.92,
                'volume' => 247_419,
                'averageVolume' => 544_354,
                'change' => 0.47,
                'changePercentage' => 1.1339,
                'marketCap' => 1_215_863_358,
                'companyName' => 'Gold.com, Inc.',
                'exchangeShortName' => 'NYSE',
            ]], 200);
        }

        return Http::response('Premium Query Parameter', 402);
    });

    $fmp = new FmpMarketDataProvider('https://test.local/stable', 'test-key');
    $result = $fmp->quote('GOLD');

    expect($result)->toBeArray();
    expect($result['price'])->toBe(41.92);
    expect($result['volume'])->toBe(247_419);
    expect($result['avg_volume'])->toBe(544_354);
    expect($result['daily_change'])->toBe(0.47);
    expect($result['daily_change_percent'])->toBe(1.1339);
    expect($result['market_cap'])->toBe(1_215_863_358);
    expect($result['company_name'])->toBe('Gold.com, Inc.');
    expect($result['exchange'])->toBe('NYSE');
});

it('returns null when all three endpoints are empty', function () {
    fakeFmpComposite();

    $fmp = new FmpMarketDataProvider('https://test.local/stable', 'test-key');

    expect($fmp->quote('NOPE'))->toBeNull();
});
