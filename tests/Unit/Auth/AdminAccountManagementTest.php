<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserCredit;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('user seeder seeds administrator user with administrator role', function () {
    $this->seed(UserSeeder::class);

    $admin = User::where('email', 'demo@crypto.x')->first();

    expect($admin)->not->toBeNull();
    expect($admin->name)->toBe('Admin');
    expect($admin->role)->toBe(UserRole::ADMINISTRATOR);
    expect($admin->isAdministrator())->toBeTrue();
    expect($admin->isAdmin())->toBeTrue();
    expect($admin->canToggleViewMode())->toBeTrue();
});

test('user named Admin does not auto-escalate to administrator on model retrieval', function () {
    DB::table('users')->insert([
        'name' => 'Admin',
        'email' => 'attacker@example.com',
        'password' => bcrypt('secret123'),
        'role' => 'guest',
        'view_mode' => 'stocks',
        'credit_balance' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $retrieved = User::where('email', 'attacker@example.com')->first();

    expect($retrieved->role)->toBe(UserRole::GUEST);
    expect($retrieved->isAdmin())->toBeFalse();
    expect($retrieved->isAdministrator())->toBeFalse();

    // Verify database row remains unchanged and no hidden write occurred
    $dbRow = DB::table('users')->where('email', 'attacker@example.com')->first();
    expect($dbRow->role)->toBe('guest');
});

test('legacy admin migration backfill promotes pre-existing admin rows', function () {
    $legacyId = DB::table('users')->insertGetId([
        'name' => 'Admin',
        'email' => 'demo@crypto.x',
        'password' => bcrypt('secret123'),
        'role' => 'guest',
        'view_mode' => 'stocks',
        'credit_balance' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_10_23_200000_promote_legacy_admin_and_create_user_credits_table.php');
    $migration->up();

    $promoted = DB::table('users')->where('id', $legacyId)->first();
    expect($promoted->role)->toBe(UserRole::ADMINISTRATOR->value);
    expect($promoted->view_mode)->toBe('stocks');
});

test('apply credit adjusts user credit balance and creates user credit record', function () {
    $admin = User::factory()->administrator()->create();
    $user = User::factory()->guest()->create(['credit_balance' => 10.00]);

    $credit = $user->applyCredit(25.50, 'Promotional grant', $admin, 'credit');

    expect($user->fresh()->credit_balance)->toBe('35.50');
    expect($user->fresh()->formattedCreditBalance())->toBe('$35.50');

    expect($credit)->toBeInstanceOf(UserCredit::class);
    expect((float) $credit->amount)->toBe(25.50);
    expect((float) $credit->balance_after)->toBe(35.50);
    expect($credit->admin_id)->toBe($admin->id);
    expect($credit->description)->toBe('Promotional grant');
    expect($credit->formattedAmount())->toBe('+$25.50');
    expect($credit->formattedBalanceAfter())->toBe('$35.50');

    // Test debit / credit deduction
    $debit = $user->applyCredit(-15.00, 'Deduction for service', $admin, 'debit');

    expect($user->fresh()->credit_balance)->toBe('20.50');
    expect($debit->formattedAmount())->toBe('-$15.00');
    expect($debit->formattedBalanceAfter())->toBe('$20.50');
});

test('assignRole updates role and aligns view mode for single domain roles', function () {
    $user = User::factory()->guest()->create(['view_mode' => 'stocks']);

    $user->assignRole(UserRole::CRYPTO);
    expect($user->fresh()->role)->toBe(UserRole::CRYPTO);
    expect($user->fresh()->view_mode)->toBe('crypto');

    $user->assignRole(UserRole::EQUITIES);
    expect($user->fresh()->role)->toBe(UserRole::EQUITIES);
    expect($user->fresh()->view_mode)->toBe('stocks');

    $user->assignRole(UserRole::FULL_SUBSCRIPTION);
    expect($user->fresh()->role)->toBe(UserRole::FULL_SUBSCRIPTION);

    $user->removeRole();
    expect($user->fresh()->role)->toBe(UserRole::GUEST);
    expect($user->fresh()->view_mode)->toBe('stocks');
});

test('user credits relationship orders transactions descending', function () {
    $user = User::factory()->guest()->create();

    $user->applyCredit(10.00, 'First');
    $user->applyCredit(20.00, 'Second');
    $user->applyCredit(30.00, 'Third');

    $credits = $user->credits;
    expect($credits)->toHaveCount(3);
    expect($credits->first()->description)->toBe('Third');
    expect($credits->last()->description)->toBe('First');
});
