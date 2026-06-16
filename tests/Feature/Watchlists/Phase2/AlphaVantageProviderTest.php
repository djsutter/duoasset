<?php

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use App\Services\MarketData\AlphaVantageMarketDataProvider;
use App\Services\MarketData\MarketDataProviderInterface;
use App\Services\MarketData\NullMarketDataProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function avStock(string $symbol = 'AAPL'): Stock
{
    $sector = Sector::factory()->create();
    $industry = Industry::factory()->create(['sector_id' => $sector->id]);
    $sub = SubIndustry::factory()->create(['industry_id' => $industry->id]);

    return Stock::factory()->create([
        'symbol' => $symbol,
        'exchange' => Exchange::NASDAQ->value,
        'currency' => Currency::USD->value,
        'company_name' => "$symbol Inc.",
        'sector_id' => $sector->id,
        'industry_id' => $industry->id,
        'sub_industry_id' => $sub->id,
    ]);
}

function avProvider(int $ttl = 86_400): AlphaVantageMarketDataProvider
{
    return new AlphaVantageMarketDataProvider(
        http: app(HttpFactory::class),
        apiKey: 'TEST-KEY',
        baseUrl: 'https://www.alphavantage.co/query',
        cacheTtlSeconds: $ttl,
        timeoutSeconds: 5,
    );
}

beforeEach(function () {
    Cache::flush();
});

it('parses a GLOBAL_QUOTE response into a StockQuote', function () {
    Http::fake([
        'www.alphavantage.co/*' => Http::response([
            'Global Quote' => [
                '01. symbol' => 'AAPL',
                '05. price' => '195.4200',
                '06. volume' => '58231400',
                '07. latest trading day' => '2026-06-15',
                '09. change' => '2.3100',
                '10. change percent' => '1.1985%',
            ],
        ]),
    ]);

    $stock = avStock('AAPL');
    $quote = avProvider()->fetchQuote($stock);

    expect($quote)->not->toBeNull()
        ->and($quote->symbol)->toBe('AAPL')
        ->and($quote->lastPrice->toDecimal())->toBe('195.42')
        ->and($quote->dailyChange->toDecimal())->toBe('2.31')
        ->and($quote->dailyChangePercent)->toBe(11985)
        ->and($quote->volume)->toBe(58231400)
        ->and($quote->asOf?->format('Y-m-d'))->toBe('2026-06-15');
});

it('caches the response for 24h and does not re-hit the API', function () {
    Http::fake([
        'www.alphavantage.co/*' => Http::response([
            'Global Quote' => [
                '01. symbol' => 'MSFT',
                '05. price' => '410.0000',
                '09. change' => '0.0000',
                '10. change percent' => '0.0000%',
                '06. volume' => '1000',
                '07. latest trading day' => '2026-06-15',
            ],
        ]),
    ]);

    $stock = avStock('MSFT');
    $provider = avProvider();

    $provider->fetchQuote($stock);
    $provider->fetchQuote($stock);
    $provider->fetchQuote($stock);

    Http::assertSentCount(1);
});

it('returns null when the API returns an empty Global Quote', function () {
    Http::fake([
        'www.alphavantage.co/*' => Http::response(['Global Quote' => []]),
    ]);

    $quote = avProvider()->fetchQuote(avStock('NOPE'));

    expect($quote)->toBeNull();
});

it('throws on rate-limit notes and does not poison the cache', function () {
    Http::fake([
        'www.alphavantage.co/*' => Http::sequence()
            ->push(['Note' => 'Thank you for using Alpha Vantage! Our standard API call frequency is 5 calls per minute and 25 calls per day.'])
            ->push([
                'Global Quote' => [
                    '01. symbol' => 'TSLA',
                    '05. price' => '250.5000',
                    '06. volume' => '500',
                    '07. latest trading day' => '2026-06-15',
                    '09. change' => '1.0000',
                    '10. change percent' => '0.4000%',
                ],
            ]),
    ]);

    $stock = avStock('TSLA');
    $provider = avProvider();

    expect(fn () => $provider->fetchQuote($stock))->toThrow(RuntimeException::class);

    // Cache must NOT contain the bad payload — second call should hit HTTP again and succeed.
    $quote = $provider->fetchQuote($stock);
    expect($quote)->not->toBeNull()
        ->and($quote->lastPrice->toDecimal())->toBe('250.50');
});

it('binds the Null provider when no API key is configured', function () {
    config()->set('services.alpha_vantage.key', null);

    $resolved = app(MarketDataProviderInterface::class);

    expect($resolved)->toBeInstanceOf(NullMarketDataProvider::class);
});

it('binds the Alpha Vantage provider when an API key is configured', function () {
    config()->set('services.alpha_vantage.key', 'live-key');

    $resolved = app()->make(MarketDataProviderInterface::class);

    expect($resolved)->toBeInstanceOf(AlphaVantageMarketDataProvider::class)
        ->and($resolved->name())->toBe('alpha_vantage');
});
