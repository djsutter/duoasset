<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('command fails when email does not exist', function () {
    $this->artisan('auth:reset-password', ['email' => 'nonexistent@example.com', '--password' => 'new-secret-123'])
        ->expectsOutputToContain('No user found with email [nonexistent@example.com].')
        ->assertFailed();
});

test('command resets password non-interactively with --password option', function () {
    Event::fake([PasswordReset::class]);

    $user = User::factory()->create([
        'email' => 'operator@example.com',
        'password' => Hash::make('old-password-123'),
        'remember_token' => 'original-token',
    ]);

    // Insert dummy reset token and session if tables exist
    if (Schema::hasTable('password_reset_tokens')) {
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => 'dummy-reset-token',
            'created_at' => now(),
        ]);
    }

    if (Schema::hasTable('sessions')) {
        DB::table('sessions')->insert([
            'id' => 'session-123',
            'user_id' => $user->id,
            'payload' => 'payload',
            'last_activity' => time(),
        ]);
    }

    $this->artisan('auth:reset-password', [
        'email' => 'operator@example.com',
        '--password' => 'updated-secure-password-456',
    ])
        ->expectsOutputToContain('Password successfully reset for [operator@example.com].')
        ->doesntExpectOutputToContain('updated-secure-password-456')
        ->assertSuccessful();

    $user->refresh();

    expect(Hash::check('updated-secure-password-456', $user->password))->toBeTrue();
    expect(Hash::check('old-password-123', $user->password))->toBeFalse();
    expect($user->remember_token)->not->toBe('original-token');

    if (Schema::hasTable('password_reset_tokens')) {
        expect(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0);
    }

    if (Schema::hasTable('sessions')) {
        expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    }

    Event::assertDispatched(PasswordReset::class, fn ($e) => $e->user->id === $user->id);
});

test('command resets password and reveals temporary password when --generate is used', function () {
    $user = User::factory()->create([
        'email' => 'temp-pass@example.com',
        'password' => Hash::make('prior-password-123'),
    ]);

    $this->artisan('auth:reset-password', [
        'email' => 'temp-pass@example.com',
        '--generate' => true,
    ])
        ->expectsOutputToContain('Password successfully reset for [temp-pass@example.com].')
        ->expectsOutputToContain('Temporary password:')
        ->assertSuccessful();

    $user->refresh();
    expect(Hash::check('prior-password-123', $user->password))->toBeFalse();
    expect(strlen($user->password))->toBeGreaterThan(10);
});

test('command prompts interactively for password and confirmation', function () {
    $user = User::factory()->create([
        'email' => 'interactive@example.com',
        'password' => Hash::make('prior-password-123'),
    ]);

    $this->artisan('auth:reset-password', ['email' => 'interactive@example.com'])
        ->expectsQuestion('Enter new password', 'interactive-new-secret-123')
        ->expectsQuestion('Confirm new password', 'interactive-new-secret-123')
        ->expectsOutputToContain('Password successfully reset for [interactive@example.com].')
        ->assertSuccessful();

    $user->refresh();
    expect(Hash::check('interactive-new-secret-123', $user->password))->toBeTrue();
});

test('command fails when interactive password confirmation does not match', function () {
    $user = User::factory()->create([
        'email' => 'mismatch@example.com',
        'password' => Hash::make('prior-password-123'),
    ]);

    $this->artisan('auth:reset-password', ['email' => 'mismatch@example.com'])
        ->expectsQuestion('Enter new password', 'first-attempt-pass')
        ->expectsQuestion('Confirm new password', 'different-attempt-pass')
        ->expectsOutputToContain('Password confirmation does not match.')
        ->assertFailed();

    $user->refresh();
    expect(Hash::check('prior-password-123', $user->password))->toBeTrue();
});

test('command rejects passwords shorter than 8 characters', function () {
    $user = User::factory()->create([
        'email' => 'shortpass@example.com',
        'password' => Hash::make('original-password-123'),
    ]);

    $this->artisan('auth:reset-password', [
        'email' => 'shortpass@example.com',
        '--password' => 'short',
    ])
        ->expectsOutputToContain('The password must be at least 8 characters.')
        ->assertFailed();

    $user->refresh();
    expect(Hash::check('original-password-123', $user->password))->toBeTrue();
});

test('command prompts for email if argument is omitted interactively', function () {
    $user = User::factory()->create([
        'email' => 'prompted-email@example.com',
        'password' => Hash::make('original-password-123'),
    ]);

    $this->artisan('auth:reset-password', ['--password' => 'brand-new-secret-999'])
        ->expectsQuestion('Enter user email address', 'prompted-email@example.com')
        ->expectsOutputToContain('Password successfully reset for [prompted-email@example.com].')
        ->assertSuccessful();

    $user->refresh();
    expect(Hash::check('brand-new-secret-999', $user->password))->toBeTrue();
});

test('command works via aliases user:reset-password and password:reset', function () {
    $userA = User::factory()->create([
        'email' => 'alias-a@example.com',
        'password' => Hash::make('original-password-123'),
    ]);

    $this->artisan('user:reset-password', [
        'email' => 'alias-a@example.com',
        '--password' => 'alias-a-password-123',
    ])
        ->expectsOutputToContain('Password successfully reset for [alias-a@example.com].')
        ->assertSuccessful();

    expect(Hash::check('alias-a-password-123', $userA->fresh()->password))->toBeTrue();

    $userB = User::factory()->create([
        'email' => 'alias-b@example.com',
        'password' => Hash::make('original-password-123'),
    ]);

    $this->artisan('password:reset', [
        'email' => 'alias-b@example.com',
        '--password' => 'alias-b-password-123',
    ])
        ->expectsOutputToContain('Password successfully reset for [alias-b@example.com].')
        ->assertSuccessful();

    expect(Hash::check('alias-b-password-123', $userB->fresh()->password))->toBeTrue();
});

test('command clears any existing two factor authentication code', function () {
    $user = User::factory()->create([
        'email' => 'two-factor@example.com',
        'password' => Hash::make('original-password-123'),
    ]);

    $user->generateTwoFactorCode();
    expect($user->fresh()->two_factor_code)->not->toBeNull();

    $this->artisan('auth:reset-password', [
        'email' => 'two-factor@example.com',
        '--password' => 'cleared-2fa-password-123',
    ])->assertSuccessful();

    expect($user->fresh()->two_factor_code)->toBeNull();
    expect($user->fresh()->two_factor_expires_at)->toBeNull();
});

test('command matches email case-insensitively and trims whitespace', function () {
    $user = User::factory()->create([
        'email' => 'cased.user@example.com',
        'password' => Hash::make('original-password-123'),
    ]);

    $this->artisan('auth:reset-password', [
        'email' => '  CASED.USER@EXAMPLE.COM  ',
        '--password' => 'new-cased-password-123',
    ])
        ->expectsOutputToContain('Password successfully reset for [cased.user@example.com].')
        ->assertSuccessful();

    expect(Hash::check('new-cased-password-123', $user->fresh()->password))->toBeTrue();
});

test('command fails non-interactively when neither --password nor --generate is provided', function () {
    $user = User::factory()->create([
        'email' => 'nopass@example.com',
    ]);

    $this->artisan('auth:reset-password', [
        'email' => 'nopass@example.com',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('In non-interactive mode, provide either --password=<password> or --generate.')
        ->assertFailed();
});
