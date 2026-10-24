<?php

use App\Enums\UserRole;
use App\Livewire\Admin\Configuration;
use App\Livewire\Admin\ManageAccounts;
use App\Models\Currency;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    // Seed CAD currency which layout/widgets depend on
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

/*
|--------------------------------------------------------------------------
| Admin Authorization & Route Gating
|--------------------------------------------------------------------------
*/

test('unauthenticated users are redirected to login when accessing admin routes', function () {
    $this->get(route('admin.config'))->assertRedirect(route('login'));
    $this->get(route('admin.accounts'))->assertRedirect(route('login'));
});

test('non-administrator roles receive 403 forbidden when accessing admin routes', function () {
    $roles = [
        UserRole::GUEST,
        UserRole::EQUITIES,
        UserRole::CRYPTO,
        UserRole::FULL_SUBSCRIPTION,
    ];

    foreach ($roles as $role) {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.config'))
            ->assertStatus(403);

        $this->actingAs($user)
            ->get(route('admin.accounts'))
            ->assertStatus(403);
    }
});

test('administrator can access admin configuration and manage accounts routes', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)
        ->get(route('admin.config'))
        ->assertOk()
        ->assertSee('Admin Configuration')
        ->assertSee('Manage Accounts');

    $this->actingAs($admin)
        ->get(route('admin.accounts'))
        ->assertOk()
        ->assertSee('Manage Accounts');
});

/*
|--------------------------------------------------------------------------
| Manage Accounts Livewire Component
|--------------------------------------------------------------------------
*/

test('manage accounts lists accounts and filters by role and search query', function () {
    $admin = User::factory()->administrator()->create(['name' => 'Super Admin', 'email' => 'admin@domain.com']);
    $user1 = User::factory()->equities()->create(['name' => 'Alice Equity', 'email' => 'alice@domain.com']);
    $user2 = User::factory()->crypto()->create(['name' => 'Bob Crypto', 'email' => 'bob@domain.com']);

    Livewire::actingAs($admin)
        ->test(ManageAccounts::class)
        ->assertSee('Super Admin')
        ->assertSee('Alice Equity')
        ->assertSee('Bob Crypto')
        // Filter by role
        ->set('filterRole', 'crypto')
        ->assertSee('Bob Crypto')
        ->assertDontSee('Alice Equity')
        // Search by query
        ->set('filterRole', '')
        ->set('search', 'Alice')
        ->assertSee('Alice Equity')
        ->assertDontSee('Bob Crypto');
});

test('admin can create account with assigned role and initial credit', function () {
    $admin = User::factory()->administrator()->create();

    Livewire::actingAs($admin)
        ->test(ManageAccounts::class)
        ->call('openCreateModal')
        ->set('new_name', 'New Trader')
        ->set('new_email', 'trader@example.com')
        ->set('new_password', 'secret12345')
        ->set('new_role', 'equities')
        ->set('new_initial_credit', '50.00')
        ->call('createAccount')
        ->assertHasNoErrors();

    $newUser = User::where('email', 'trader@example.com')->first();
    expect($newUser)->not->toBeNull();
    expect($newUser->name)->toBe('New Trader');
    expect($newUser->role)->toBe(UserRole::EQUITIES);
    expect($newUser->view_mode)->toBe('stocks');
    expect($newUser->credit_balance)->toBe('50.00');
    expect($newUser->credits)->toHaveCount(1);
    expect((float) $newUser->credits->first()->amount)->toBe(50.00);
});

test('admin can update role for an account', function () {
    $admin = User::factory()->administrator()->create();
    $target = User::factory()->guest()->create();

    Livewire::actingAs($admin)
        ->test(ManageAccounts::class)
        ->call('openRoleModal', $target->id)
        ->set('selectedRole', 'full_subscription')
        ->call('updateRole')
        ->assertHasNoErrors();

    expect($target->fresh()->role)->toBe(UserRole::FULL_SUBSCRIPTION);
});

test('admin cannot demote their own active account from administrator', function () {
    $admin = User::factory()->administrator()->create();

    Livewire::actingAs($admin)
        ->test(ManageAccounts::class)
        ->call('openRoleModal', $admin->id)
        ->set('selectedRole', 'guest')
        ->call('updateRole')
        ->assertHasErrors(['selectedRole']);

    expect($admin->fresh()->role)->toBe(UserRole::ADMINISTRATOR);
});

test('admin can remove role from account resetting it to guest', function () {
    $admin = User::factory()->administrator()->create();
    $target = User::factory()->crypto()->create();

    Livewire::actingAs($admin)
        ->test(ManageAccounts::class)
        ->call('removeRole', $target->id);

    expect($target->fresh()->role)->toBe(UserRole::GUEST);
    expect($target->fresh()->view_mode)->toBe('stocks');
});

test('admin can apply credit and debit adjustments to an account', function () {
    $admin = User::factory()->administrator()->create();
    $target = User::factory()->guest()->create(['credit_balance' => 0.00]);

    // Apply positive credit
    Livewire::actingAs($admin)
        ->test(ManageAccounts::class)
        ->call('openCreditModal', $target->id)
        ->set('creditAction', 'add')
        ->set('creditAmount', '75.00')
        ->set('creditDescription', 'Promotion credit')
        ->call('applyCredit')
        ->assertHasNoErrors();

    expect($target->fresh()->credit_balance)->toBe('75.00');

    // Apply deduction
    Livewire::actingAs($admin)
        ->test(ManageAccounts::class)
        ->call('openCreditModal', $target->id)
        ->set('creditAction', 'deduct')
        ->set('creditAmount', '25.00')
        ->set('creditDescription', 'Adjustment')
        ->call('applyCredit')
        ->assertHasNoErrors();

    expect($target->fresh()->credit_balance)->toBe('50.00');
    expect($target->fresh()->credits)->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Admin Configuration Component
|--------------------------------------------------------------------------
*/

test('admin configuration renders stats and allows switching to accounts tab', function () {
    $admin = User::factory()->administrator()->create();
    User::factory()->guest()->count(3)->create();
    User::factory()->equities()->count(2)->create();

    Livewire::actingAs($admin)
        ->test(Configuration::class)
        ->assertSee('Admin Configuration')
        ->assertSee('System Overview')
        ->assertSee('Account Management')
        ->assertSet('tab', 'overview')
        // Switch tab to accounts
        ->call('setTab', 'accounts')
        ->assertSet('tab', 'accounts')
        ->assertSeeHtml('data-testid="embedded-manage-accounts"');
});

/*
|--------------------------------------------------------------------------
| Navigation & UI Links Verification
|--------------------------------------------------------------------------
*/

test('administrator sees admin configuration and manage accounts links in header and dropdown', function () {
    $admin = User::factory()->administrator()->create();

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('header-admin-config-button', false);
    $response->assertSee('header-manage-accounts-button', false);
    $response->assertSee('dropdown-admin-config-link', false);
    $response->assertSee('dropdown-manage-accounts-link', false);
    $response->assertSee('sidebar-admin-config-link', false);
    $response->assertSee('sidebar-manage-accounts-link', false);
});

test('non-administrator does not see admin configuration or manage accounts links', function () {
    $guest = User::factory()->guest()->create();

    $response = $this->actingAs($guest)->get(route('dashboard'));

    $response->assertOk();
    $response->assertDontSee('header-admin-config-button');
    $response->assertDontSee('header-manage-accounts-button');
    $response->assertDontSee('dropdown-admin-config-link');
    $response->assertDontSee('dropdown-manage-accounts-link');
    $response->assertDontSee('sidebar-admin-config-link');
    $response->assertDontSee('sidebar-manage-accounts-link');
});
