<?php

use App\Enums\UserRole;
use App\Livewire\MainNavigation;
use App\Livewire\ViewModeToggle;
use App\Models\Currency;
use App\Models\User;
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

test('full subscription user can toggle view mode from stocks to crypto and persist to database', function () {
    $user = User::factory()->fullSubscription()->create([
        'view_mode' => 'stocks',
    ]);

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->assertSet('viewMode', 'stocks')
        ->call('toggleViewMode')
        ->assertSet('viewMode', 'crypto')
        ->assertDispatched('view-mode-updated');

    expect($user->fresh()->view_mode)->toBe('crypto')
        ->and($user->fresh()->activeViewMode())->toBe('crypto');
});

test('full subscription user can toggle view mode from crypto back to stocks and persist to database', function () {
    $user = User::factory()->fullSubscription()->create([
        'view_mode' => 'crypto',
    ]);

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->assertSet('viewMode', 'crypto')
        ->call('toggleViewMode')
        ->assertSet('viewMode', 'stocks')
        ->assertDispatched('view-mode-updated');

    expect($user->fresh()->view_mode)->toBe('stocks')
        ->and($user->fresh()->activeViewMode())->toBe('stocks');
});

test('administrator user can set specific view mode and persist to database', function () {
    $user = User::factory()->administrator()->create([
        'view_mode' => 'stocks',
    ]);

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->call('setViewMode', 'crypto')
        ->assertSet('viewMode', 'crypto')
        ->assertDispatched('view-mode-updated');

    expect($user->fresh()->view_mode)->toBe('crypto');

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->call('setViewMode', 'stocks')
        ->assertSet('viewMode', 'stocks')
        ->assertDispatched('view-mode-updated');

    expect($user->fresh()->view_mode)->toBe('stocks');
});

test('main navigation component can toggle view mode directly for dual role users', function () {
    $user = User::factory()->fullSubscription()->create([
        'view_mode' => 'stocks',
    ]);

    Livewire::actingAs($user)
        ->test(MainNavigation::class)
        ->assertSee('Stocks')
        ->assertDontSee('Wallets')
        ->call('toggleViewMode')
        ->assertDispatched('view-mode-updated');

    expect($user->fresh()->view_mode)->toBe('crypto');

    // Re-rendering or calling refresh updates menu items to crypto perspective
    Livewire::actingAs($user->fresh())
        ->test(MainNavigation::class)
        ->assertSee('Wallets')
        ->assertDontSee('Money Flows');
});

test('reactive event view-mode-updated switches main navigation items', function () {
    $user = User::factory()->fullSubscription()->create([
        'view_mode' => 'stocks',
    ]);

    $nav = Livewire::actingAs($user)
        ->test(MainNavigation::class)
        ->assertSee('Stocks')
        ->assertDontSee('Wallets');

    // Simulate toggle updating database and dispatching event
    $user->setViewMode('crypto');

    $nav->dispatch('view-mode-updated', mode: 'crypto')
        ->assertSee('Wallets')
        ->assertDontSee('Money Flows');
});

test('guest user cannot invoke view mode toggle on ViewModeToggle component', function () {
    $user = User::factory()->guest()->create([
        'view_mode' => 'stocks',
    ]);

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->call('toggleViewMode')
        ->assertForbidden();

    expect($user->fresh()->view_mode)->toBe('stocks');
});

test('guest user cannot invoke setViewMode on ViewModeToggle component', function () {
    $user = User::factory()->guest()->create([
        'view_mode' => 'stocks',
    ]);

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->call('setViewMode', 'crypto')
        ->assertForbidden();

    expect($user->fresh()->view_mode)->toBe('stocks');
});

test('equities user cannot invoke view mode toggle', function () {
    $user = User::factory()->equities()->create([
        'view_mode' => 'stocks',
    ]);

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->call('toggleViewMode')
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test(MainNavigation::class)
        ->call('toggleViewMode')
        ->assertForbidden();

    expect($user->fresh()->view_mode)->toBe('stocks');
});

test('crypto user cannot invoke view mode toggle', function () {
    $user = User::factory()->crypto()->create([
        'view_mode' => 'crypto',
    ]);

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->call('toggleViewMode')
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test(MainNavigation::class)
        ->call('toggleViewMode')
        ->assertForbidden();

    expect($user->fresh()->view_mode)->toBe('crypto');
});

test('setting invalid view mode is rejected', function () {
    $user = User::factory()->fullSubscription()->create([
        'view_mode' => 'stocks',
    ]);

    Livewire::actingAs($user)
        ->test(ViewModeToggle::class)
        ->call('setViewMode', 'invalid_mode')
        ->assertStatus(400);

    expect($user->fresh()->view_mode)->toBe('stocks');
});

test('guest user sees prominent subscribe CTA in application header and mobile menu', function () {
    $user = User::factory()->guest()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertSee('Subscribe');
    $response->assertSee('header-guest-subscribe-button', false);
    $response->assertSee('sidebar-guest-subscribe-button', false);
});

test('subscribed users do not see guest subscribe CTA', function () {
    $roles = [
        UserRole::EQUITIES,
        UserRole::CRYPTO,
        UserRole::FULL_SUBSCRIPTION,
        UserRole::ADMINISTRATOR,
    ];

    foreach ($roles as $role) {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('header-guest-subscribe-button');
        $response->assertDontSee('sidebar-guest-subscribe-button');
    }
});

test('dual role users see view mode switcher on dashboard', function () {
    $fullSub = User::factory()->fullSubscription()->create();
    $admin = User::factory()->administrator()->create();

    $this->actingAs($fullSub)->get('/dashboard')
        ->assertOk()
        ->assertSee('Perspective Switcher');

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('Perspective Switcher');
});

test('single domain and guest users do not see view mode switcher', function () {
    $guest = User::factory()->guest()->create();
    $equities = User::factory()->equities()->create();
    $crypto = User::factory()->crypto()->create();

    $this->actingAs($guest)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Perspective Switcher');

    $this->actingAs($equities)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Perspective Switcher');

    $this->actingAs($crypto)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Perspective Switcher');
});
