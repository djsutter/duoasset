<?php

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Enums\MoatLevel;
use App\Livewire\Watchlists\Show as WatchlistShow;
use App\Models\Stock;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use App\Services\MarketData\AlphaVantageMarketDataProvider;
use App\Services\MarketData\MarketDataProviderInterface;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Force the container to bind the Alpha Vantage provider so the Livewire
 * component's autocomplete actually fires (the default binding is the
 * Null provider when no AV key is configured).
 */
function bindAlphaVantage(): void
{
    config(['services.alpha_vantage.key' => 'test-key']);
    config(['services.alpha_vantage.base_url' => 'https://av.test/query']);

    app()->bind(MarketDataProviderInterface::class, function ($app) {
        return new AlphaVantageMarketDataProvider(
            http: $app->make(\Illuminate\Http\Client\Factory::class),
            apiKey: 'test-key',
            baseUrl: 'https://av.test/query',
            cacheTtlSeconds: 60,
            timeoutSeconds: 2,
        );
    });
}

it('does not query Alpha Vantage until 3 characters are typed', function () {
    bindAlphaVantage();
    Http::fake(); // any AV call would be intercepted; we just want to see none fired

    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbolQuery', 'AA') // < 3 chars
        ->assertSet('searchResults', [])
        ->assertSet('showSearchResults', false);

    Http::assertNothingSent();
});

it('populates the autocomplete dropdown from Alpha Vantage SYMBOL_SEARCH', function () {
    bindAlphaVantage();

    Http::fake([
        'av.test/query*' => Http::response([
            'bestMatches' => [
                [
                    '1. symbol' => 'AAPL',
                    '2. name' => 'Apple Inc',
                    '3. type' => 'Equity',
                    '4. region' => 'United States',
                    '8. currency' => 'USD',
                    '9. matchScore' => '1.0000',
                ],
                [
                    '1. symbol' => 'AAP',
                    '2. name' => 'Advance Auto Parts',
                    '3. type' => 'Equity',
                    '4. region' => 'United States',
                    '8. currency' => 'USD',
                    '9. matchScore' => '0.8000',
                ],
            ],
        ], 200),
    ]);

    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    $component = Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbolQuery', 'AAPL');

    $component->assertSet('showSearchResults', true);
    $results = $component->get('searchResults');
    expect($results)->toHaveCount(2);
    expect($results[0]['symbol'])->toBe('AAPL');
    expect($results[0]['name'])->toBe('Apple Inc');
    expect($results[0]['currency'])->toBe('USD');
});

it('selectSymbol populates company name, exchange and currency from OVERVIEW', function () {
    bindAlphaVantage();

    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, 'function=SYMBOL_SEARCH')) {
            return Http::response([
                'bestMatches' => [[
                    '1. symbol' => 'MSFT',
                    '2. name' => 'Microsoft Corp', // SYMBOL_SEARCH name
                    '3. type' => 'Equity',
                    '4. region' => 'United States',
                    '8. currency' => 'USD',
                    '9. matchScore' => '1.0000',
                ]],
            ], 200);
        }
        if (str_contains($url, 'function=OVERVIEW')) {
            return Http::response([
                'Symbol' => 'MSFT',
                'Name' => 'Microsoft Corporation', // canonical via OVERVIEW
                'Exchange' => 'NASDAQ',
                'Currency' => 'USD',
                'Country' => 'USA',
                'Sector' => 'Technology',
                'Industry' => 'Software',
            ], 200);
        }
        return Http::response([], 200);
    });

    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbolQuery', 'MSFT')
        ->call('selectSymbol', 0)
        ->assertSet('symbol', 'MSFT')
        ->assertSet('company_name', 'Microsoft Corporation') // OVERVIEW takes precedence over SYMBOL_SEARCH
        ->assertSet('exchange', Exchange::NASDAQ->value)
        ->assertSet('currency', 'USD')
        ->assertSet('showSearchResults', false);
});

it('falls back to SYMBOL_SEARCH currency when OVERVIEW returns nothing', function () {
    bindAlphaVantage();

    Http::fake(function ($request) {
        $url = (string) $request->url();
        if (str_contains($url, 'function=SYMBOL_SEARCH')) {
            return Http::response([
                'bestMatches' => [[
                    '1. symbol' => 'SHOP',
                    '2. name' => 'Shopify Inc',
                    '3. type' => 'Equity',
                    '4. region' => 'Toronto',
                    '8. currency' => 'CAD',
                    '9. matchScore' => '1.0000',
                ]],
            ], 200);
        }
        // OVERVIEW returns empty payload (AV does this for some non-US listings)
        return Http::response([], 200);
    });

    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbolQuery', 'SHOP')
        ->call('selectSymbol', 0)
        ->assertSet('symbol', 'SHOP')
        ->assertSet('company_name', 'Shopify Inc')
        ->assertSet('currency', 'CAD');
});

it('allows adding a stock with only the four required fields (taxonomy auto-provisioned)', function () {
    bindAlphaVantage();
    Http::fake(); // no AV calls in this scenario

    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbol', 'NVDA')
        ->set('company_name', 'NVIDIA Corp')
        ->set('exchange', Exchange::NASDAQ->value)
        ->set('currency', Currency::USD->value)
        // sector_id / industry_id / sub_industry_id / moat_level deliberately left blank
        ->call('addStock')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('stocks', [
        'symbol' => 'NVDA',
        'exchange' => 'NASDAQ',
        'currency' => 'USD',
        'company_name' => 'NVIDIA Corp',
    ]);

    $stock = Stock::firstWhere('symbol', 'NVDA');
    // Provisioner guarantees a complete taxonomy chain.
    expect($stock->sector_id)->not->toBeNull();
    expect($stock->industry_id)->not->toBeNull();
    expect($stock->sub_industry_id)->not->toBeNull();

    $item = WatchlistItem::firstWhere('watchlist_id', $w->id);
    expect($item)->not->toBeNull();
    expect($item->moat_level)->toBe(MoatLevel::Medium); // default fallback
});

it('rejects an add when currency is missing (still required)', function () {
    bindAlphaVantage();
    Http::fake();

    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbol', 'AMD')
        ->set('company_name', 'AMD')
        ->set('exchange', Exchange::NASDAQ->value)
        // currency intentionally left blank
        ->call('addStock')
        ->assertHasErrors(['currency']);
});
