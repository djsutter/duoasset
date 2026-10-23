<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('user role enum contains expected cases and backed values', function () {
    expect(UserRole::GUEST->value)->toBe('guest')
        ->and(UserRole::EQUITIES->value)->toBe('equities')
        ->and(UserRole::CRYPTO->value)->toBe('crypto')
        ->and(UserRole::FULL_SUBSCRIPTION->value)->toBe('full_subscription')
        ->and(UserRole::ADMINISTRATOR->value)->toBe('administrator');
});

test('user role enum capability methods enforce domain boundaries', function () {
    // Stock access
    expect(UserRole::GUEST->hasStockAccess())->toBeTrue()
        ->and(UserRole::EQUITIES->hasStockAccess())->toBeTrue()
        ->and(UserRole::CRYPTO->hasStockAccess())->toBeFalse()
        ->and(UserRole::FULL_SUBSCRIPTION->hasStockAccess())->toBeTrue()
        ->and(UserRole::ADMINISTRATOR->hasStockAccess())->toBeTrue();

    // Crypto access
    expect(UserRole::GUEST->hasCryptoAccess())->toBeFalse()
        ->and(UserRole::EQUITIES->hasCryptoAccess())->toBeFalse()
        ->and(UserRole::CRYPTO->hasCryptoAccess())->toBeTrue()
        ->and(UserRole::FULL_SUBSCRIPTION->hasCryptoAccess())->toBeTrue()
        ->and(UserRole::ADMINISTRATOR->hasCryptoAccess())->toBeTrue();

    // Toggle view mode
    expect(UserRole::GUEST->canToggleViewMode())->toBeFalse()
        ->and(UserRole::EQUITIES->canToggleViewMode())->toBeFalse()
        ->and(UserRole::CRYPTO->canToggleViewMode())->toBeFalse()
        ->and(UserRole::FULL_SUBSCRIPTION->canToggleViewMode())->toBeTrue()
        ->and(UserRole::ADMINISTRATOR->canToggleViewMode())->toBeTrue();

    // Higher than guest
    expect(UserRole::GUEST->isHigherThanGuest())->toBeFalse()
        ->and(UserRole::EQUITIES->isHigherThanGuest())->toBeTrue()
        ->and(UserRole::CRYPTO->isHigherThanGuest())->toBeTrue()
        ->and(UserRole::FULL_SUBSCRIPTION->isHigherThanGuest())->toBeTrue()
        ->and(UserRole::ADMINISTRATOR->isHigherThanGuest())->toBeTrue();
});

test('user model casts role attribute to UserRole enum and has default values', function () {
    $user = User::factory()->create();

    expect($user->role)->toBe(UserRole::GUEST)
        ->and($user->view_mode)->toBe('stocks');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'role' => 'guest',
        'view_mode' => 'stocks',
    ]);
});

test('user model proxies role capability methods correctly', function () {
    $guest = User::factory()->guest()->create();
    expect($guest->hasStockAccess())->toBeTrue()
        ->and($guest->hasCryptoAccess())->toBeFalse()
        ->and($guest->canToggleViewMode())->toBeFalse()
        ->and($guest->isHigherThanGuest())->toBeFalse()
        ->and($guest->isGuest())->toBeTrue()
        ->and($guest->isAdministrator())->toBeFalse();

    $equities = User::factory()->equities()->create();
    expect($equities->hasStockAccess())->toBeTrue()
        ->and($equities->hasCryptoAccess())->toBeFalse()
        ->and($equities->canToggleViewMode())->toBeFalse()
        ->and($equities->isHigherThanGuest())->toBeTrue()
        ->and($equities->isGuest())->toBeFalse()
        ->and($equities->isAdministrator())->toBeFalse();

    $crypto = User::factory()->crypto()->create();
    expect($crypto->hasStockAccess())->toBeFalse()
        ->and($crypto->hasCryptoAccess())->toBeTrue()
        ->and($crypto->canToggleViewMode())->toBeFalse()
        ->and($crypto->isHigherThanGuest())->toBeTrue()
        ->and($crypto->isGuest())->toBeFalse()
        ->and($crypto->isAdministrator())->toBeFalse();

    $fullSub = User::factory()->fullSubscription()->create();
    expect($fullSub->hasStockAccess())->toBeTrue()
        ->and($fullSub->hasCryptoAccess())->toBeTrue()
        ->and($fullSub->canToggleViewMode())->toBeTrue()
        ->and($fullSub->isHigherThanGuest())->toBeTrue()
        ->and($fullSub->isGuest())->toBeFalse()
        ->and($fullSub->isAdministrator())->toBeFalse();

    $admin = User::factory()->administrator()->create();
    expect($admin->hasStockAccess())->toBeTrue()
        ->and($admin->hasCryptoAccess())->toBeTrue()
        ->and($admin->canToggleViewMode())->toBeTrue()
        ->and($admin->isHigherThanGuest())->toBeTrue()
        ->and($admin->isGuest())->toBeFalse()
        ->and($admin->isAdministrator())->toBeTrue()
        ->and($admin->isAdmin())->toBeTrue();
});

test('user factory creates users with expected roles and view modes for each state', function () {
    $guest = User::factory()->guest()->create();
    expect($guest->role)->toBe(UserRole::GUEST)
        ->and($guest->view_mode)->toBe('stocks');

    $equities = User::factory()->equities()->create();
    expect($equities->role)->toBe(UserRole::EQUITIES)
        ->and($equities->view_mode)->toBe('stocks');

    $crypto = User::factory()->crypto()->create();
    expect($crypto->role)->toBe(UserRole::CRYPTO)
        ->and($crypto->view_mode)->toBe('crypto');

    $fullSub = User::factory()->fullSubscription()->create();
    expect($fullSub->role)->toBe(UserRole::FULL_SUBSCRIPTION)
        ->and($fullSub->view_mode)->toBe('stocks');

    $admin = User::factory()->administrator()->create();
    expect($admin->role)->toBe(UserRole::ADMINISTRATOR)
        ->and($admin->view_mode)->toBe('stocks');
});

test('user model persists view mode updates to database', function () {
    $user = User::factory()->fullSubscription()->create([
        'view_mode' => 'stocks',
    ]);

    expect($user->view_mode)->toBe('stocks');

    $user->setViewMode('crypto');

    expect($user->view_mode)->toBe('crypto');

    // Confirm database persistence
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'view_mode' => 'crypto',
    ]);

    $user->refresh();
    expect($user->view_mode)->toBe('crypto');

    $user->setViewMode('stocks');
    $user->refresh();
    expect($user->view_mode)->toBe('stocks');
});

test('setViewMode throws exception on invalid mode', function () {
    $user = User::factory()->fullSubscription()->create();

    $user->setViewMode('invalid_mode');
})->throws(\InvalidArgumentException::class);

test('activeViewMode returns fixed domain for single-domain roles and dynamic for dual roles', function () {
    $guest = User::factory()->guest()->create();
    expect($guest->activeViewMode())->toBe('stocks');

    $equities = User::factory()->equities()->create();
    expect($equities->activeViewMode())->toBe('stocks');

    $crypto = User::factory()->crypto()->create();
    expect($crypto->activeViewMode())->toBe('crypto');

    $fullSub = User::factory()->fullSubscription()->create(['view_mode' => 'stocks']);
    expect($fullSub->activeViewMode())->toBe('stocks');
    $fullSub->setViewMode('crypto');
    expect($fullSub->activeViewMode())->toBe('crypto');

    $admin = User::factory()->administrator()->create(['view_mode' => 'stocks']);
    expect($admin->activeViewMode())->toBe('stocks');
    $admin->setViewMode('crypto');
    expect($admin->activeViewMode())->toBe('crypto');
});
