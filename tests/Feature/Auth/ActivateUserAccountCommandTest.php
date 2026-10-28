<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;

test('command fails when email does not exist', function () {
    $this->artisan('auth:activate-account', ['email' => 'nonexistent@example.com'])
        ->expectsOutputToContain('No user found with email [nonexistent@example.com].')
        ->assertFailed();
});

test('command fails when no email is provided in non-interactive mode', function () {
    $this->artisan('auth:activate-account', ['--no-interaction' => true])
        ->expectsOutputToContain('The email argument is required in non-interactive mode.')
        ->assertFailed();
});

test('command fails when email is whitespace', function () {
    $this->artisan('auth:activate-account', ['email' => '   '])
        ->expectsOutputToContain('Email address cannot be empty.')
        ->assertFailed();
});

test('command activates unverified user and clears activation token', function () {
    Event::fake([Verified::class]);

    $user = User::factory()->unverified()->create([
        'email' => 'unverified.operator@example.com',
    ]);
    $user->forceFill(['activation_token' => 'active-token-abc'])->save();

    expect($user->hasVerifiedEmail())->toBeFalse();
    expect($user->activation_token)->toBe('active-token-abc');

    $this->artisan('auth:activate-account', ['email' => 'unverified.operator@example.com'])
        ->expectsOutputToContain('Account successfully activated for [unverified.operator@example.com].')
        ->assertSuccessful();

    $user->refresh();

    expect($user->hasVerifiedEmail())->toBeTrue();
    expect($user->activation_token)->toBeNull();

    Event::assertDispatched(Verified::class, fn ($e) => $e->user->id === $user->id);
});

test('command is case-insensitive when matching email', function () {
    Event::fake([Verified::class]);

    $user = User::factory()->unverified()->create([
        'email' => 'mixed.case@example.com',
    ]);

    $this->artisan('auth:activate-account', ['email' => 'MIXED.CASE@EXAMPLE.COM'])
        ->expectsOutputToContain('Account successfully activated for [mixed.case@example.com].')
        ->assertSuccessful();

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('command is idempotent when run on already active and verified user', function () {
    Event::fake([Verified::class]);

    $user = User::factory()->create([
        'email' => 'already.active@example.com',
        'email_verified_at' => now()->subDay(),
    ]);
    $initialVerifiedAt = $user->email_verified_at->toIso8601String();

    $this->artisan('auth:activate-account', ['email' => 'already.active@example.com'])
        ->expectsOutputToContain('Account [already.active@example.com] is already active and verified.')
        ->assertSuccessful();

    $user->refresh();
    expect($user->email_verified_at->toIso8601String())->toBe($initialVerifiedAt);
    expect($user->activation_token)->toBeNull();

    Event::assertNotDispatched(Verified::class);
});

test('command clears leftover activation token on already verified user', function () {
    $user = User::factory()->create([
        'email' => 'token.cleanup@example.com',
        'email_verified_at' => now()->subDay(),
    ]);
    $user->forceFill(['activation_token' => 'leftover-token'])->save();

    $this->artisan('auth:activate-account', ['email' => 'token.cleanup@example.com'])
        ->expectsOutputToContain('Account successfully activated for [token.cleanup@example.com].')
        ->assertSuccessful();

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect($user->activation_token)->toBeNull();
});

test('command prompts interactively for email if not passed as argument', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'interactive.user@example.com',
    ]);

    $this->artisan('auth:activate-account')
        ->expectsQuestion('Enter user email address', 'interactive.user@example.com')
        ->expectsOutputToContain('Account successfully activated for [interactive.user@example.com].')
        ->assertSuccessful();

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('command aliases user:activate and account:activate work correctly', function () {
    $user1 = User::factory()->unverified()->create(['email' => 'alias1@example.com']);
    $user2 = User::factory()->unverified()->create(['email' => 'alias2@example.com']);

    $this->artisan('user:activate', ['email' => 'alias1@example.com'])
        ->expectsOutputToContain('Account successfully activated for [alias1@example.com].')
        ->assertSuccessful();

    $this->artisan('account:activate', ['email' => 'alias2@example.com'])
        ->expectsOutputToContain('Account successfully activated for [alias2@example.com].')
        ->assertSuccessful();

    expect($user1->fresh()->hasVerifiedEmail())->toBeTrue();
    expect($user2->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('activating unverified account allows user to proceed through login', function () {
    $user = User::factory()->unverified()->create([
        'email' => 'login.blocked@example.com',
        'password' => Hash::make('secret-password-123'),
    ]);

    // Initial login attempt fails because account is not activated
    Volt::test('auth.login')
        ->set('email', 'login.blocked@example.com')
        ->set('password', 'secret-password-123')
        ->call('login')
        ->assertHasErrors(['email' => __('Your account has not been activated. Please check your email to activate your account before logging in.')]);

    // Activate the account via CLI
    $this->artisan('auth:activate-account', ['email' => 'login.blocked@example.com'])
        ->assertSuccessful();

    // Subsequent login succeeds and proceeds to 2FA challenge
    Volt::test('auth.login')
        ->set('email', 'login.blocked@example.com')
        ->set('password', 'secret-password-123')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('two-factor.challenge', absolute: false));
});
