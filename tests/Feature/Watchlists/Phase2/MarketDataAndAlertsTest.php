<?php

use App\Enums\AlertRuleType;
use App\Enums\AlertSeverity;
use App\Enums\Exchange;
use App\Enums\MoatLevel;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistAlertEvent;
use App\Models\WatchlistAlertRule;
use App\Models\WatchlistItem;
use App\Notifications\WatchlistAlertMail;
use App\Services\Alerts\AlertEvaluator;
use App\Services\MarketData\MarketDataProviderInterface;
use App\Services\MarketData\StockQuote;
use App\Types\FiatMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * Test double for the market-data provider — fully in-memory.
 */
class FakeMarketDataProvider implements MarketDataProviderInterface
{
    /** @var array<string, StockQuote> */
    public array $quotes = [];

    public function name(): string
    {
        return 'fake';
    }

    public function fetchQuote(Stock $stock): ?StockQuote
    {
        return $this->quotes[$stock->symbol] ?? null;
    }

    public function fetchQuotes(iterable $stocks): iterable
    {
        $out = [];
        foreach ($stocks as $s) {
            if ($q = $this->fetchQuote($s)) {
                $out[] = $q;
            }
        }

        return $out;
    }
}

function p2MakeStock(string $symbol = 'AAPL', string $currency = 'USD'): Stock
{
    $sector = Sector::factory()->create();
    $industry = Industry::factory()->create(['sector_id' => $sector->id]);
    $sub = SubIndustry::factory()->create(['industry_id' => $industry->id]);

    return Stock::factory()->create([
        'symbol' => $symbol,
        'exchange' => Exchange::NASDAQ->value,
        'currency' => $currency,
        'company_name' => "$symbol Inc.",
        'sector_id' => $sector->id,
        'industry_id' => $industry->id,
        'sub_industry_id' => $sub->id,
    ]);
}

function p2MakeItem(Stock $stock, ?User $user = null): WatchlistItem
{
    $user ??= User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    return WatchlistItem::factory()->create([
        'watchlist_id' => $w->id,
        'stock_id' => $stock->id,
        'moat_level' => MoatLevel::High->value,
    ]);
}

test('stock model exposes market data columns as FiatMoney casts', function () {
    $stock = p2MakeStock();
    $stock->last_price = FiatMoney::fromDecimal('100.00', 'USD');
    $stock->daily_change = FiatMoney::fromDecimal('1.25', 'USD');
    $stock->daily_change_percent = 12500; // 1.25%
    $stock->volume = 1_000_000;
    $stock->market_cap = FiatMoney::fromDecimal('1000000000', 'USD');
    $stock->last_checked_at = now();
    $stock->save();

    $fresh = $stock->fresh();

    expect($fresh->last_price)->toBeInstanceOf(FiatMoney::class)
        ->and($fresh->last_price->toDecimal())->toBe('100.00')
        ->and($fresh->daily_change_percent)->toBe(12500)
        ->and($fresh->volume)->toBe(1_000_000);
});

test('null market data provider is bound by default and is safe to call', function () {
    // Force the default (no Alpha Vantage key) binding regardless of .env.
    config()->set('services.alpha_vantage.key', null);
    $this->app->forgetInstance(MarketDataProviderInterface::class);
    $provider = app(MarketDataProviderInterface::class);

    expect($provider->name())->toBe('null')
        ->and($provider->fetchQuote(p2MakeStock()))->toBeNull();
});

test('market-watch:update-quotes runs cleanly with the null provider', function () {
    config()->set('services.alpha_vantage.key', null);
    $this->app->forgetInstance(MarketDataProviderInterface::class);
    p2MakeStock('AAPL');
    p2MakeStock('MSFT');

    $this->artisan('market-watch:update-quotes')
        ->expectsOutputToContain('null')
        ->assertSuccessful();

    // Stocks should be unchanged because the null provider returns nothing.
    expect(Stock::whereNotNull('last_price')->count())->toBe(0);
});

test('command applies quotes from a custom provider and evaluates alerts', function () {
    Notification::fake();

    $stock = p2MakeStock('AAPL');
    $item = p2MakeItem($stock);

    WatchlistAlertRule::factory()->create([
        'watchlist_item_id' => $item->id,
        'type' => AlertRuleType::PriceAbove->value,
        'severity' => AlertSeverity::Warning->value,
        'currency' => 'USD',
        'target_price' => FiatMoney::fromDecimal('150.00', 'USD'),
    ]);

    $fake = new FakeMarketDataProvider;
    $fake->quotes['AAPL'] = new StockQuote(
        symbol: 'AAPL',
        exchange: 'NASDAQ',
        lastPrice: FiatMoney::fromDecimal('175.00', 'USD'),
        dailyChange: FiatMoney::fromDecimal('5.00', 'USD'),
        dailyChangePercent: 30000,
        volume: 2_500_000,
        marketCap: FiatMoney::fromDecimal('2500000000', 'USD'),
        asOf: CarbonImmutable::now(),
    );

    $this->app->instance(MarketDataProviderInterface::class, $fake);

    $this->artisan('market-watch:update-quotes', ['--force' => true])
        ->assertSuccessful();

    $stock->refresh();
    expect($stock->last_price->toDecimal())->toBe('175.00')
        ->and($stock->volume)->toBe(2_500_000)
        ->and($stock->last_checked_at)->not->toBeNull();

    expect(WatchlistAlertEvent::count())->toBe(1);

    $event = WatchlistAlertEvent::first();
    expect($event->severity)->toBe(AlertSeverity::Warning)
        ->and($event->message)->toContain('crossed above');

    Notification::assertSentTo($item->watchlist->user, WatchlistAlertMail::class);
});

test('price below rule triggers only when price drops to or under target', function () {
    Notification::fake();
    $stock = p2MakeStock('NVDA');
    $item = p2MakeItem($stock);

    WatchlistAlertRule::factory()->create([
        'watchlist_item_id' => $item->id,
        'type' => AlertRuleType::PriceBelow->value,
        'severity' => AlertSeverity::Critical->value,
        'currency' => 'USD',
        'target_price' => FiatMoney::fromDecimal('500.00', 'USD'),
    ]);

    // Above target — no alert.
    $stock->forceFill([
        'last_price' => FiatMoney::fromDecimal('600.00', 'USD'),
        'currency' => 'USD',
    ])->save();
    app(AlertEvaluator::class)->evaluateStock($stock->fresh());
    expect(WatchlistAlertEvent::count())->toBe(0);

    // At/under target — triggers.
    $stock->forceFill([
        'last_price' => FiatMoney::fromDecimal('500.00', 'USD'),
    ])->save();
    app(AlertEvaluator::class)->evaluateStock($stock->fresh());
    expect(WatchlistAlertEvent::count())->toBe(1);
});

test('percent change rule triggers above its bps threshold', function () {
    Notification::fake();
    $stock = p2MakeStock('TSLA');
    $item = p2MakeItem($stock);

    WatchlistAlertRule::factory()->create([
        'watchlist_item_id' => $item->id,
        'type' => AlertRuleType::PercentChange->value,
        'parameters' => ['threshold_bps' => 50000], // 5.0%
    ]);

    // 4% — below threshold.
    $stock->forceFill(['daily_change_percent' => 40000])->save();
    app(AlertEvaluator::class)->evaluateStock($stock->fresh());
    expect(WatchlistAlertEvent::count())->toBe(0);

    // 6% — above threshold.
    $stock->forceFill(['daily_change_percent' => 60000])->save();
    app(AlertEvaluator::class)->evaluateStock($stock->fresh());
    expect(WatchlistAlertEvent::count())->toBe(1);
});

test('manual review rules never auto-trigger', function () {
    Notification::fake();
    $stock = p2MakeStock('GME');
    $item = p2MakeItem($stock);

    WatchlistAlertRule::factory()->create([
        'watchlist_item_id' => $item->id,
        'type' => AlertRuleType::ManualReview->value,
    ]);

    $stock->forceFill([
        'last_price' => FiatMoney::fromDecimal('1.00', 'USD'),
        'daily_change_percent' => 999999,
    ])->save();

    $events = app(AlertEvaluator::class)->evaluateStock($stock->fresh());
    expect($events)->toBe([])->and(WatchlistAlertEvent::count())->toBe(0);
});

test('triggered event records notification history and updates rule timestamp', function () {
    Notification::fake();
    $stock = p2MakeStock('AMD');
    $item = p2MakeItem($stock);

    $rule = WatchlistAlertRule::factory()->create([
        'watchlist_item_id' => $item->id,
        'type' => AlertRuleType::PriceAbove->value,
        'target_price' => FiatMoney::fromDecimal('100.00', 'USD'),
    ]);

    $stock->forceFill([
        'last_price' => FiatMoney::fromDecimal('120.00', 'USD'),
    ])->save();

    app(AlertEvaluator::class)->evaluateStock($stock->fresh());

    $rule->refresh();
    $event = WatchlistAlertEvent::first();

    expect($rule->last_triggered_at)->not->toBeNull()
        ->and($event->notifications)->toBeArray()
        ->and(array_key_exists('mail', $event->notifications ?? []))->toBeTrue();
});

test('inactive rules do not trigger', function () {
    Notification::fake();
    $stock = p2MakeStock('INTC');
    $item = p2MakeItem($stock);

    WatchlistAlertRule::factory()->create([
        'watchlist_item_id' => $item->id,
        'type' => AlertRuleType::PriceAbove->value,
        'is_active' => false,
        'target_price' => FiatMoney::fromDecimal('10.00', 'USD'),
    ]);

    $stock->forceFill([
        'last_price' => FiatMoney::fromDecimal('999.00', 'USD'),
    ])->save();

    app(AlertEvaluator::class)->evaluateStock($stock->fresh());
    expect(WatchlistAlertEvent::count())->toBe(0);
});
