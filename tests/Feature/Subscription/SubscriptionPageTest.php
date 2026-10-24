<?php

use App\Enums\UserRole;
use App\Livewire\Subscription\Index as SubscriptionIndex;
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

test('unauthenticated users are redirected to login from subscription page', function () {
    $this->get(route('subscription'))->assertRedirect(route('login'));
    $this->get(route('subscription.index'))->assertRedirect(route('login'));
});

test('guest user can view subscription page with plan options and credit balance', function () {
    $guest = User::factory()->guest()->create([
        'name' => 'Trial User',
        'credit_balance' => 15.00,
    ]);

    $this->actingAs($guest)
        ->get(route('subscription'))
        ->assertOk()
        ->assertSee('Trial User')
        ->assertSee('Guest')
        ->assertSee('$15.00')
        ->assertSee('Equities')
        ->assertSee('Full Subscription')
        ->assertSee('Crypto');
});

test('guest user can upgrade to equities subscription and consume available credits', function () {
    $guest = User::factory()->guest()->create([
        'credit_balance' => 20.00,
    ]);

    Livewire::actingAs($guest)
        ->test(SubscriptionIndex::class)
        ->call('upgradeToPlan', 'equities')
        ->assertDispatched('view-mode-updated')
        ->assertSee('Congratulations! Your account has been upgraded');

    expect($guest->fresh()->role)->toBe(UserRole::EQUITIES);
    expect($guest->fresh()->view_mode)->toBe('stocks');
    // $29 price with $20 credit leaves $0.00 credit balance
    expect((float) $guest->fresh()->credit_balance)->toBe(0.00);
    expect($guest->credits)->toHaveCount(1);
    expect((float) $guest->credits->first()->amount)->toBe(-20.00);
});

test('guest user can upgrade to full subscription', function () {
    $guest = User::factory()->guest()->create([
        'credit_balance' => 60.00,
    ]);

    Livewire::actingAs($guest)
        ->test(SubscriptionIndex::class)
        ->call('upgradeToPlan', 'full_subscription')
        ->assertDispatched('view-mode-updated');

    expect($guest->fresh()->role)->toBe(UserRole::FULL_SUBSCRIPTION);
    // $49 price deducted from $60 leaves $11
    expect((float) $guest->fresh()->credit_balance)->toBe(11.00);
});

test('guest user can upgrade to crypto subscription', function () {
    $guest = User::factory()->guest()->create();

    Livewire::actingAs($guest)
        ->test(SubscriptionIndex::class)
        ->call('upgradeToPlan', 'crypto')
        ->assertDispatched('view-mode-updated');

    expect($guest->fresh()->role)->toBe(UserRole::CRYPTO);
    expect($guest->fresh()->view_mode)->toBe('crypto');
});

test('subscribed user sees active plan status for their current subscription tier', function () {
    $subscriber = User::factory()->equities()->create();

    Livewire::actingAs($subscriber)
        ->test(SubscriptionIndex::class)
        ->assertSeeHtml('data-testid="plan-btn-equities-active"')
        ->assertSee('Active Plan');
});

test('subscribe buttons in header, sidebar, and guest preview banner navigate to subscription page', function () {
    $guest = User::factory()->guest()->create();

    $response = $this->actingAs($guest)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee(route('subscription'));
    $response->assertDontSee('href="#subscribe"');
});
