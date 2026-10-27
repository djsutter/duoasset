<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Notifications\TwoFactorAuthenticationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

test('registration sends activation email and does not authenticate immediately', function () {
    Notification::fake();

    $response = Volt::test('auth.register')
        ->set('name', 'Pending Guest')
        ->set('email', 'pending@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('login', absolute: false));

    $this->assertGuest();

    $user = User::where('email', 'pending@example.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::GUEST)
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->activation_token)->not->toBeNull();

    Notification::assertSentTo($user, AccountActivationNotification::class, function ($notification) use ($user) {
        return hash('sha256', $notification->token) === $user->activation_token;
    });

    Notification::assertNotSentTo($user, VerifyEmail::class);
});

test('activation link verifies, activates, logs in user on first registration, and redirects to dashboard', function () {
    Notification::fake();
    Event::fake([Verified::class]);

    $response = Volt::test('auth.register')
        ->set('name', 'First Activation User')
        ->set('email', 'first-activation@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register');

    $user = User::where('email', 'first-activation@example.com')->firstOrFail();

    $token = null;
    Notification::assertSentTo($user, AccountActivationNotification::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    expect($token)->not->toBeNull();

    // Click activation hyperlink
    $activationResponse = $this->get(route('activate-account', ['token' => $token]));

    $activationResponse->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->activation_token)->toBeNull();

    Event::assertDispatched(Verified::class);
});

test('activation link works via alias route account.activate', function () {
    $user = User::factory()->unverified()->create();
    $token = $user->sendAccountActivationNotification();

    $response = $this->get(route('account.activate', ['token' => $token]));

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->activation_token)->toBeNull();
});

test('activation link cannot be reused after initial activation', function () {
    $user = User::factory()->unverified()->create();
    $token = $user->sendAccountActivationNotification();

    // First click activates and logs in
    $firstResponse = $this->get(route('activate-account', ['token' => $token]));
    $firstResponse->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    // Logout
    $this->post('/logout');
    $this->assertGuest();

    // Replay the same activation link
    $secondResponse = $this->get(route('activate-account', ['token' => $token]));
    $secondResponse->assertRedirect(route('login'));
    $secondResponse->assertSessionHas('error');

    $this->assertGuest();
});

test('invalid activation link fails to activate and redirects to login', function () {
    $response = $this->get(route('activate-account', ['token' => 'invalid-token-12345']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
    $this->assertGuest();
});

test('unactivated user cannot log in normally before activation', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create([
        'password' => bcrypt('password'),
    ]);

    $response = Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $response->assertHasErrors('email');
    $this->assertGuest();
    $this->assertNull(session('login.id'));

    Notification::assertNothingSent();
});

test('activated guest subsequently requires two-factor authentication on login', function () {
    Notification::fake();

    $user = User::factory()->create([
        'role' => UserRole::GUEST,
        'password' => bcrypt('password'),
    ]);

    $loginResponse = Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $loginResponse
        ->assertHasNoErrors()
        ->assertRedirect(route('two-factor.challenge', absolute: false));

    $this->assertGuest();
    $this->assertEquals($user->id, session('login.id'));

    Notification::assertSentTo($user, TwoFactorAuthenticationCode::class);

    // Complete 2FA
    $user->refresh();
    $code = $user->generateTwoFactorCode();

    session(['login.id' => $user->id]);

    $challengeResponse = Volt::test('auth.two-factor-challenge')
        ->set('code', $code)
        ->call('verify');

    $challengeResponse
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('existing higher-role user continues to require two-factor authentication on login', function () {
    Notification::fake();

    $user = User::factory()->administrator()->create([
        'password' => bcrypt('password'),
    ]);

    $loginResponse = Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $loginResponse
        ->assertHasNoErrors()
        ->assertRedirect(route('two-factor.challenge', absolute: false));

    $this->assertGuest();
    $this->assertEquals($user->id, session('login.id'));

    Notification::assertSentTo($user, TwoFactorAuthenticationCode::class);
});

test('unverified authenticated user is redirected by verified middleware', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect(route('verification.notice'));
});
