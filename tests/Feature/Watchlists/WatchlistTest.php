<?php

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Enums\MoatLevel;
use App\Livewire\Watchlists\Index as WatchlistIndex;
use App\Livewire\Watchlists\Show as WatchlistShow;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use Livewire\Livewire;

function makeClassification(): array
{
    $sector = Sector::factory()->create();
    $industry = Industry::factory()->create(['sector_id' => $sector->id]);
    $sub = SubIndustry::factory()->create(['industry_id' => $industry->id]);

    return [$sector, $industry, $sub];
}

function makeStock(array $overrides = []): Stock
{
    [$sector, $industry, $sub] = makeClassification();

    return Stock::factory()->create(array_merge([
        'symbol' => 'AAPL',
        'exchange' => Exchange::NASDAQ->value,
        'currency' => Currency::USD->value,
        'company_name' => 'Apple Inc.',
        'sector_id' => $sector->id,
        'industry_id' => $industry->id,
        'sub_industry_id' => $sub->id,
    ], $overrides));
}

test('a stock can be created with required classifications', function () {
    $stock = makeStock();

    expect($stock->symbol)->toBe('AAPL')
        ->and($stock->exchange)->toBe(Exchange::NASDAQ)
        ->and($stock->currency)->toBe(Currency::USD)
        ->and($stock->sector)->not->toBeNull()
        ->and($stock->industry)->not->toBeNull()
        ->and($stock->subIndustry)->not->toBeNull();

    $this->assertDatabaseHas('stocks', ['symbol' => 'AAPL', 'exchange' => 'NASDAQ']);
});

test('symbol+exchange is unique for stocks', function () {
    makeStock();
    expect(fn () => makeStock())
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('stocks are reused across watchlist items', function () {
    $stock = makeStock();
    $user = User::factory()->create();
    $w1 = Watchlist::factory()->create(['user_id' => $user->id]);
    $w2 = Watchlist::factory()->create(['user_id' => $user->id]);

    WatchlistItem::factory()->create([
        'watchlist_id' => $w1->id,
        'stock_id' => $stock->id,
        'moat_level' => MoatLevel::High->value,
    ]);
    WatchlistItem::factory()->create([
        'watchlist_id' => $w2->id,
        'stock_id' => $stock->id,
        'moat_level' => MoatLevel::Medium->value,
    ]);

    expect(Stock::count())->toBe(1);
    expect(WatchlistItem::count())->toBe(2);
});

test('a user can create a watchlist via Livewire', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WatchlistIndex::class)
        ->set('name', 'My Watchlist')
        ->set('description', 'Top picks')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('watchlists', [
        'user_id' => $user->id,
        'name' => 'My Watchlist',
    ]);
});

test('watchlist name is required', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(WatchlistIndex::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

test('a user can edit a watchlist', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id, 'name' => 'Old']);

    Livewire::actingAs($user)
        ->test(WatchlistIndex::class)
        ->call('edit', $w->id)
        ->set('name', 'New')
        ->call('save');

    expect($w->fresh()->name)->toBe('New');
});

test('a user can delete their watchlist', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(WatchlistIndex::class)
        ->call('delete', $w->id);

    $this->assertDatabaseMissing('watchlists', ['id' => $w->id]);
});

test('setting a watchlist as default unsets others', function () {
    $user = User::factory()->create();
    $w1 = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => true]);
    $w2 = Watchlist::factory()->create(['user_id' => $user->id, 'is_default' => false]);

    Livewire::actingAs($user)
        ->test(WatchlistIndex::class)
        ->call('setDefault', $w2->id);

    expect($w1->fresh()->is_default)->toBeFalse();
    expect($w2->fresh()->is_default)->toBeTrue();
});

test('a user cannot edit or delete another user\'s watchlist', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $owner->id]);

    Livewire::actingAs($intruder)
        ->test(WatchlistIndex::class)
        ->call('delete', $w->id)
        ->assertStatus(403);

    $this->assertDatabaseHas('watchlists', ['id' => $w->id]);
});

test('a user cannot view another user\'s watchlist', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $owner->id]);

    Livewire::actingAs($intruder)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->assertStatus(403);
});

test('a user can add a stock to a watchlist', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);
    [$sector, $industry, $sub] = makeClassification();

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbol', 'msft')
        ->set('exchange', Exchange::NASDAQ->value)
        ->set('currency', Currency::USD->value)
        ->set('company_name', 'Microsoft Corp.')
        ->set('sector_id', $sector->id)
        ->set('industry_id', $industry->id)
        ->set('sub_industry_id', $sub->id)
        ->set('moat_level', MoatLevel::VeryHigh->value)
        ->set('target_price', '500.00')
        ->set('stop_price', '350.50')
        ->call('addStock')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('stocks', ['symbol' => 'MSFT', 'exchange' => 'NASDAQ']);
    $item = WatchlistItem::firstWhere('watchlist_id', $w->id);
    expect($item)->not->toBeNull();
    expect($item->moat_level)->toBe(MoatLevel::VeryHigh);
    expect($item->currency)->toBe('USD');
    expect($item->target_price->toDecimal())->toBe('500.00');
    expect($item->stop_price->toDecimal())->toBe('350.50');
});

test('adding the same stock to a watchlist is prevented', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);
    $stock = makeStock();
    WatchlistItem::factory()->create([
        'watchlist_id' => $w->id,
        'stock_id' => $stock->id,
    ]);

    [$s2, $i2, $si2] = makeClassification();

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbol', $stock->symbol)
        ->set('exchange', $stock->exchange->value)
        ->set('currency', $stock->currency->value)
        ->set('company_name', $stock->company_name)
        ->set('sector_id', $s2->id)
        ->set('industry_id', $i2->id)
        ->set('sub_industry_id', $si2->id)
        ->set('moat_level', MoatLevel::Low->value)
        ->call('addStock')
        ->assertHasErrors(['symbol']);

    expect(WatchlistItem::where('watchlist_id', $w->id)->count())->toBe(1);
});

test('negative or zero target/stop prices are rejected', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);
    [$sector, $industry, $sub] = makeClassification();

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('symbol', 'TSLA')
        ->set('exchange', Exchange::NASDAQ->value)
        ->set('currency', Currency::USD->value)
        ->set('company_name', 'Tesla')
        ->set('sector_id', $sector->id)
        ->set('industry_id', $industry->id)
        ->set('sub_industry_id', $sub->id)
        ->set('moat_level', MoatLevel::Medium->value)
        ->set('target_price', '-1')
        ->set('stop_price', '0')
        ->call('addStock')
        ->assertHasErrors(['target_price', 'stop_price']);
});

test('a user can remove a stock from a watchlist', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);
    $stock = makeStock();
    $item = WatchlistItem::factory()->create([
        'watchlist_id' => $w->id,
        'stock_id' => $stock->id,
    ]);

    Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->call('removeItem', $item->id);

    $this->assertDatabaseMissing('watchlist_items', ['id' => $item->id]);
});

test('sort by symbol orders items correctly', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    $z = makeStock(['symbol' => 'ZZZ', 'exchange' => Exchange::NYSE->value]);
    $a = makeStock(['symbol' => 'AAA', 'exchange' => Exchange::NYSE->value]);

    WatchlistItem::factory()->create(['watchlist_id' => $w->id, 'stock_id' => $z->id]);
    WatchlistItem::factory()->create(['watchlist_id' => $w->id, 'stock_id' => $a->id]);

    $component = Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w]);

    $items = $component->viewData('items');
    expect($items->first()->stock->symbol)->toBe('AAA');

    $component->call('sortBy', 'symbol'); // toggle to desc
    $items = $component->viewData('items');
    expect($items->first()->stock->symbol)->toBe('ZZZ');
});

test('filter by exchange narrows items', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    $nyse = makeStock(['symbol' => 'NY1', 'exchange' => Exchange::NYSE->value]);
    $nasdaq = makeStock(['symbol' => 'ND1', 'exchange' => Exchange::NASDAQ->value]);
    WatchlistItem::factory()->create(['watchlist_id' => $w->id, 'stock_id' => $nyse->id]);
    WatchlistItem::factory()->create(['watchlist_id' => $w->id, 'stock_id' => $nasdaq->id]);

    $component = Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('filterExchange', Exchange::NYSE->value);

    $items = $component->viewData('items');
    expect($items)->toHaveCount(1);
    expect($items->first()->stock->symbol)->toBe('NY1');
});

test('search filters by symbol or company name', function () {
    $user = User::factory()->create();
    $w = Watchlist::factory()->create(['user_id' => $user->id]);

    $a = makeStock(['symbol' => 'AAA', 'company_name' => 'Acme Co', 'exchange' => Exchange::NYSE->value]);
    $b = makeStock(['symbol' => 'BBB', 'company_name' => 'Beta Co', 'exchange' => Exchange::NYSE->value]);
    WatchlistItem::factory()->create(['watchlist_id' => $w->id, 'stock_id' => $a->id]);
    WatchlistItem::factory()->create(['watchlist_id' => $w->id, 'stock_id' => $b->id]);

    $component = Livewire::actingAs($user)
        ->test(WatchlistShow::class, ['watchlist' => $w])
        ->set('search', 'Acme');

    $items = $component->viewData('items');
    expect($items)->toHaveCount(1);
    expect($items->first()->stock->symbol)->toBe('AAA');
});

test('moat level enum exposes required cases with labels', function () {
    expect(MoatLevel::cases())->toHaveCount(5);
    expect(MoatLevel::VeryHigh->label())->toBe('Very High');
    expect(MoatLevel::VeryLow->label())->toBe('Very Low');
});
