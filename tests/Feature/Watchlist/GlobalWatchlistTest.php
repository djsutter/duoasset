<?php

use App\Enums\UserRole;
use App\Livewire\Watchlists\Index;
use App\Livewire\Watchlists\Show;
use App\Models\Stock;
use App\Models\User;
use App\Models\Watchlist;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('multiple users can create watchlists with the same name', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA);
    Livewire::test(Index::class)
        ->set('name', 'Growth Stocks')
        ->call('save')
        ->assertHasNoErrors();

    $this->actingAs($userB);
    Livewire::test(Index::class)
        ->set('name', 'Growth Stocks')
        ->call('save')
        ->assertHasNoErrors();

    expect(Watchlist::where('name', 'Growth Stocks')->count())->toBe(2);
});

test('a single user cannot create duplicate watchlists with the same name', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    Livewire::test(Index::class)
        ->set('name', 'Dividend Portfolio')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test(Index::class)
        ->set('name', 'Dividend Portfolio')
        ->call('save')
        ->assertHasErrors(['name']);

    expect(Watchlist::where('user_id', $user->id)->where('name', 'Dividend Portfolio')->count())->toBe(1);
});

test('an administrator can create a global watchlist', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMINISTRATOR]);

    $this->actingAs($admin);
    Livewire::test(Index::class)
        ->set('name', 'Global Core Holdings')
        ->set('is_global', true)
        ->call('save')
        ->assertHasNoErrors();

    $watchlist = Watchlist::where('name', 'Global Core Holdings')->first();
    expect($watchlist)->not->toBeNull();
    expect($watchlist->is_global)->toBeTrue();
});

test('a regular user cannot create a global watchlist', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);

    $this->actingAs($user);
    Livewire::test(Index::class)
        ->set('name', 'User Global Attempt')
        ->set('is_global', true)
        ->call('save')
        ->assertHasNoErrors();

    $watchlist = Watchlist::where('name', 'User Global Attempt')->first();
    expect($watchlist)->not->toBeNull();
    expect($watchlist->is_global)->toBeFalse();
});

test('all users can view a global watchlist in the watchlists index', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMINISTRATOR]);
    $global = Watchlist::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Market Leaders',
        'is_global' => true,
    ]);

    $regularUser = User::factory()->create(['role' => UserRole::EQUITIES]);

    $this->actingAs($regularUser);
    Livewire::test(Index::class)
        ->assertSee('Market Leaders')
        ->assertSee('Global');
});

test('regular users cannot edit, delete, or add items to a global watchlist', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMINISTRATOR]);
    $global = Watchlist::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Admin Global',
        'is_global' => true,
    ]);
    $stock = Stock::factory()->create(['symbol' => 'AAPL']);
    $item = WatchlistItem::factory()->create([
        'watchlist_id' => $global->id,
        'stock_id' => $stock->id,
    ]);

    $regularUser = User::factory()->create(['role' => UserRole::EQUITIES]);

    $this->actingAs($regularUser);

    // Cannot edit in Index
    Livewire::test(Index::class)
        ->call('edit', $global->id)
        ->assertForbidden();

    // Cannot delete in Index
    Livewire::test(Index::class)
        ->call('delete', $global->id)
        ->assertForbidden();

    // In Show view, cannot open add form or remove items
    Livewire::test(Show::class, ['watchlist' => $global])
        ->call('openAddForm')
        ->assertForbidden();

    Livewire::test(Show::class, ['watchlist' => $global])
        ->call('removeItem', $item->id)
        ->assertForbidden();
});

test('administrator can edit and modify items on a global watchlist', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMINISTRATOR]);
    $global = Watchlist::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Global Admin List',
        'is_global' => true,
    ]);
    $stock = Stock::factory()->create(['symbol' => 'MSFT']);

    $this->actingAs($admin);

    Livewire::test(Index::class)
        ->call('edit', $global->id)
        ->assertHasNoErrors()
        ->set('description', 'Updated by admin')
        ->call('save')
        ->assertHasNoErrors();

    expect($global->fresh()->description)->toBe('Updated by admin');

    Livewire::test(Show::class, ['watchlist' => $global])
        ->call('openAddForm')
        ->assertHasNoErrors()
        ->set('symbol', 'MSFT')
        ->set('exchange', 'NASDAQ')
        ->set('currency', 'USD')
        ->set('company_name', 'Microsoft Corp.')
        ->call('addStock')
        ->assertHasNoErrors();

    expect($global->items()->where('stock_id', $stock->id)->exists())->toBeTrue();
});

test('dashboard falls back to global watchlist when user has no personal watchlist', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMINISTRATOR]);
    $global = Watchlist::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Global Top Stocks',
        'is_global' => true,
    ]);

    $regularUser = User::factory()->create(['role' => UserRole::EQUITIES]);

    $this->actingAs($regularUser);
    $dashboard = new \App\Livewire\Dashboard;
    $resolved = $dashboard->resolveWatchlist();

    expect($resolved)->not->toBeNull();
    expect($resolved->id)->toBe($global->id);
    expect($resolved->name)->toBe('Global Top Stocks');
});
