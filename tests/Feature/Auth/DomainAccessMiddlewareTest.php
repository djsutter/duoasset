<?php

use App\Livewire\MainNavigation;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    Currency::factory()->create([
        'currency_code' => 'CAD',
        'name' => 'Canadian Dollar',
        'symbol' => '$',
        'scale' => 2,
        'display_scale' => 2,
        'type' => 'fiat',
        'is_active' => true,
    ]);
});

test('unauthenticated users are redirected to login when accessing domain routes', function () {
    $this->get('/stocks')->assertRedirect('/login');
    $this->get('/wallets')->assertRedirect('/login');
    $this->get('/money-flows')->assertRedirect('/login');
    $this->get('/currencies')->assertRedirect('/login');
});

test('crypto user cannot access stock domain routes', function () {
    $user = User::factory()->crypto()->create();
    $this->actingAs($user);

    $this->get('/stocks')->assertStatus(403);
    $this->get('/watchlists')->assertStatus(403);
    $this->get('/money-flows')->assertStatus(403);
    $this->get('/watchlist/earnings-surprises')->assertStatus(403);
    $this->get('/watchlist/eps-revisions')->assertStatus(403);
    $this->get('/watchlist/stock-buy-setups')->assertStatus(403);
});

test('equities user cannot access crypto domain routes', function () {
    $user = User::factory()->equities()->create();
    $this->actingAs($user);

    $this->get('/wallets')->assertStatus(403);
    $this->get('/currencies')->assertStatus(403);
    $this->get('/platforms')->assertStatus(403);
    $this->get('/reports')->assertStatus(403);
    $this->get('/import')->assertStatus(403);
    $this->get('/tax/schedule3')->assertStatus(403);
    $this->get('/acb')->assertStatus(403);
});

test('guest user cannot access crypto domain routes', function () {
    $user = User::factory()->guest()->create();
    $this->actingAs($user);

    $this->get('/wallets')->assertStatus(403);
    $this->get('/currencies')->assertStatus(403);
    $this->get('/reports')->assertStatus(403);
    $this->get('/tax/schedule3')->assertStatus(403);
});

test('guest user can access stock domain routes', function () {
    $user = User::factory()->guest()->create();
    $this->actingAs($user);

    $this->get('/stocks')->assertOk();
    $this->get('/watchlists')->assertOk();
    $this->get('/money-flows')->assertOk();
});

test('equities user can access stock domain routes', function () {
    $user = User::factory()->equities()->create();
    $this->actingAs($user);

    $this->get('/stocks')->assertOk();
    $this->get('/watchlists')->assertOk();
    $this->get('/money-flows')->assertOk();
});

test('crypto user can access crypto domain routes', function () {
    $user = User::factory()->crypto()->create();
    $this->actingAs($user);

    $this->get('/wallets')->assertOk();
    $this->get('/currencies')->assertOk();
    $this->get('/reports')->assertOk();
    $this->get('/tax/schedule3')->assertOk();
});

test('full subscription user can access both stock and crypto routes', function () {
    $user = User::factory()->fullSubscription()->create();
    $this->actingAs($user);

    $this->get('/stocks')->assertOk();
    $this->get('/watchlists')->assertOk();
    $this->get('/wallets')->assertOk();
    $this->get('/currencies')->assertOk();
    $this->get('/reports')->assertOk();
});

test('administrator user can access both stock and crypto routes', function () {
    $user = User::factory()->administrator()->create();
    $this->actingAs($user);

    $this->get('/stocks')->assertOk();
    $this->get('/watchlists')->assertOk();
    $this->get('/wallets')->assertOk();
    $this->get('/currencies')->assertOk();
    $this->get('/reports')->assertOk();
});

test('full subscription user visiting domain routes aligns view_mode', function () {
    $user = User::factory()->fullSubscription()->create(['view_mode' => 'stocks']);
    $this->actingAs($user);

    $this->get('/wallets')->assertOk();
    $user->refresh();
    expect($user->view_mode)->toBe('crypto');

    $this->get('/stocks')->assertOk();
    $user->refresh();
    expect($user->view_mode)->toBe('stocks');
});

test('authorization gates correctly authorize each role', function () {
    $guest = User::factory()->guest()->create();
    $equities = User::factory()->equities()->create();
    $crypto = User::factory()->crypto()->create();
    $fullSub = User::factory()->fullSubscription()->create();
    $admin = User::factory()->administrator()->create();

    // access-stocks
    expect(Gate::forUser($guest)->allows('access-stocks'))->toBeTrue();
    expect(Gate::forUser($equities)->allows('access-stocks'))->toBeTrue();
    expect(Gate::forUser($crypto)->allows('access-stocks'))->toBeFalse();
    expect(Gate::forUser($fullSub)->allows('access-stocks'))->toBeTrue();
    expect(Gate::forUser($admin)->allows('access-stocks'))->toBeTrue();

    // access-crypto
    expect(Gate::forUser($guest)->allows('access-crypto'))->toBeFalse();
    expect(Gate::forUser($equities)->allows('access-crypto'))->toBeFalse();
    expect(Gate::forUser($crypto)->allows('access-crypto'))->toBeTrue();
    expect(Gate::forUser($fullSub)->allows('access-crypto'))->toBeTrue();
    expect(Gate::forUser($admin)->allows('access-crypto'))->toBeTrue();

    // toggle-view-mode
    expect(Gate::forUser($guest)->allows('toggle-view-mode'))->toBeFalse();
    expect(Gate::forUser($equities)->allows('toggle-view-mode'))->toBeFalse();
    expect(Gate::forUser($crypto)->allows('toggle-view-mode'))->toBeFalse();
    expect(Gate::forUser($fullSub)->allows('toggle-view-mode'))->toBeTrue();
    expect(Gate::forUser($admin)->allows('toggle-view-mode'))->toBeTrue();

    // admin
    expect(Gate::forUser($guest)->allows('admin'))->toBeFalse();
    expect(Gate::forUser($equities)->allows('admin'))->toBeFalse();
    expect(Gate::forUser($crypto)->allows('admin'))->toBeFalse();
    expect(Gate::forUser($fullSub)->allows('admin'))->toBeFalse();
    expect(Gate::forUser($admin)->allows('admin'))->toBeTrue();
});

test('main navigation renders crypto items for crypto user', function () {
    $user = User::factory()->crypto()->create();

    Livewire::actingAs($user)
        ->test(MainNavigation::class)
        ->assertSee('Dashboard')
        ->assertSee('Wallets')
        ->assertSee('Currencies')
        ->assertSee('Platforms')
        ->assertSee('Reports')
        ->assertDontSee('Money Flows')
        ->assertDontSee('Setups')
        ->assertDontSee('Watchlists');
});

test('main navigation renders stock items for equities and guest users', function () {
    $equities = User::factory()->equities()->create();

    Livewire::actingAs($equities)
        ->test(MainNavigation::class)
        ->assertSee('Dashboard')
        ->assertSee('Stocks')
        ->assertSee('Money Flows')
        ->assertSee('Setups')
        ->assertSee('Watchlists')
        ->assertSee('EPS')
        ->assertDontSee('Wallets')
        ->assertDontSee('Currencies')
        ->assertDontSee('Platforms');

    $guest = User::factory()->guest()->create();

    Livewire::actingAs($guest)
        ->test(MainNavigation::class)
        ->assertSee('Dashboard')
        ->assertSee('Stocks')
        ->assertSee('Money Flows')
        ->assertSee('Setups')
        ->assertSee('Watchlists')
        ->assertSee('EPS')
        ->assertDontSee('Wallets')
        ->assertDontSee('Currencies')
        ->assertDontSee('Platforms');
});

test('main navigation renders items matching view_mode for dual role users', function () {
    $fullSubStocks = User::factory()->fullSubscription()->create(['view_mode' => 'stocks']);

    Livewire::actingAs($fullSubStocks)
        ->test(MainNavigation::class)
        ->assertSee('Stocks')
        ->assertSee('Money Flows')
        ->assertDontSee('Wallets');

    $fullSubCrypto = User::factory()->fullSubscription()->create(['view_mode' => 'crypto']);

    Livewire::actingAs($fullSubCrypto)
        ->test(MainNavigation::class)
        ->assertSee('Wallets')
        ->assertSee('Currencies')
        ->assertDontSee('Money Flows');
});
