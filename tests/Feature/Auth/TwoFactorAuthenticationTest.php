<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\TwoFactorAuthenticationCode;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt as LivewireVolt;

test('non-guest users are redirected to two-factor challenge on valid login and notification is sent', function (UserRole $role) {
    Notification::fake();

    $user = User::factory()->create([
        'role' => $role,
        'password' => bcrypt('password'),
    ]);

    $response = LivewireVolt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('two-factor.challenge', absolute: false));

    $this->assertGuest();
    $this->assertEquals($user->id, session('login.id'));

    $user->refresh();
    expect($user->two_factor_code)->not->toBeNull();
    expect($user->two_factor_expires_at)->not->toBeNull();

    Notification::assertSentTo($user, TwoFactorAuthenticationCode::class, function ($notification) {
        return strlen($notification->code) === 6;
    });
})->with([
    UserRole::EQUITIES,
    UserRole::CRYPTO,
    UserRole::FULL_SUBSCRIPTION,
    UserRole::ADMINISTRATOR,
]);

test('guest users require two-factor authentication on login', function () {
    Notification::fake();

    $user = User::factory()->create([
        'role' => UserRole::GUEST,
        'password' => bcrypt('password'),
    ]);

    $response = LivewireVolt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('two-factor.challenge', absolute: false));

    $this->assertGuest();
    $this->assertEquals($user->id, session('login.id'));

    $user->refresh();
    expect($user->two_factor_code)->not->toBeNull();
    expect($user->two_factor_expires_at)->not->toBeNull();

    Notification::assertSentTo($user, TwoFactorAuthenticationCode::class, function ($notification) {
        return strlen($notification->code) === 6;
    });
});

test('two-factor challenge screen redirects to login if no login session exists', function () {
    $response = $this->get(route('two-factor.challenge'));

    $response->assertRedirect(route('login', absolute: false));
});

test('two-factor challenge screen renders when session login id is set', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);

    $response = $this->withSession(['login.id' => $user->id])
        ->get(route('two-factor.challenge'));

    $response->assertStatus(200);
});

test('user can authenticate with valid two-factor code', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);
    $code = $user->generateTwoFactorCode();

    session(['login.id' => $user->id]);

    $response = LivewireVolt::test('auth.two-factor-challenge')
        ->set('code', $code)
        ->call('verify');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);

    $user->refresh();
    expect($user->two_factor_code)->toBeNull();
    expect($user->two_factor_expires_at)->toBeNull();
    expect(session('login.id'))->toBeNull();
});

test('user cannot authenticate with invalid two-factor code', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);
    $user->generateTwoFactorCode();

    session(['login.id' => $user->id]);

    $response = LivewireVolt::test('auth.two-factor-challenge')
        ->set('code', '000000')
        ->call('verify');

    $response->assertHasErrors(['code']);
    $this->assertGuest();
});

test('user cannot authenticate with expired two-factor code', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);
    $code = $user->generateTwoFactorCode();

    // Expire the code
    $user->forceFill(['two_factor_expires_at' => now()->subMinute()])->save();

    session(['login.id' => $user->id]);

    $response = LivewireVolt::test('auth.two-factor-challenge')
        ->set('code', $code)
        ->call('verify');

    $response->assertHasErrors(['code']);
    $this->assertGuest();
});

test('user can request a new two-factor code via resend', function () {
    Notification::fake();

    $user = User::factory()->create(['role' => UserRole::EQUITIES]);
    $firstCode = $user->generateTwoFactorCode();

    session(['login.id' => $user->id]);

    $response = LivewireVolt::test('auth.two-factor-challenge')
        ->call('resend');

    $response->assertHasNoErrors();
    Notification::assertSentTo($user, TwoFactorAuthenticationCode::class);

    $user->refresh();
    expect($user->verifyTwoFactorCode($firstCode))->toBeFalse();
});

test('user can cancel two-factor authentication and return to login', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);

    session(['login.id' => $user->id]);

    $response = LivewireVolt::test('auth.two-factor-challenge')
        ->call('cancel');

    $response->assertRedirect(route('login', absolute: false));
    expect(session('login.id'))->toBeNull();
});

test('two-factor middleware redirects pending 2fa users visiting login to challenge', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);

    $response = $this->withSession(['login.id' => $user->id])
        ->get('/login');

    $response->assertRedirect(route('two-factor.challenge'));
});

test('two-factor middleware redirects pending 2fa users visiting other guest routes to challenge', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);

    $this->withSession(['login.id' => $user->id])
        ->get('/')
        ->assertRedirect(route('two-factor.challenge'));

    $this->withSession(['login.id' => $user->id])
        ->get(route('register'))
        ->assertRedirect(route('two-factor.challenge'));

    $this->withSession(['login.id' => $user->id])
        ->get(route('password.request'))
        ->assertRedirect(route('two-factor.challenge'));
});

test('two-factor middleware redirects non-challenge livewire updates to challenge', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);

    // Livewire update for a different component
    $response = $this->withSession(['login.id' => $user->id])
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('default-livewire.update'), [
            'components' => [
                [
                    'snapshot' => json_encode(['memo' => ['name' => 'admin.manage-accounts']]),
                    'updates' => [],
                    'calls' => [],
                ],
            ],
        ]);

    $response->assertRedirect(route('two-factor.challenge'));

    // Livewire update with empty components
    $emptyResponse = $this->withSession(['login.id' => $user->id])
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('default-livewire.update'), [
            'components' => [],
        ]);

    $emptyResponse->assertRedirect(route('two-factor.challenge'));

    // Livewire update with mixed components (one challenge, one non-challenge)
    $mixedResponse = $this->withSession(['login.id' => $user->id])
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(route('default-livewire.update'), [
            'components' => [
                [
                    'snapshot' => json_encode(['memo' => ['name' => 'auth.two-factor-challenge']]),
                    'updates' => [],
                    'calls' => [],
                ],
                [
                    'snapshot' => json_encode(['memo' => ['name' => 'admin.manage-accounts']]),
                    'updates' => [],
                    'calls' => [],
                ],
            ],
        ]);

    $mixedResponse->assertRedirect(route('two-factor.challenge'));
});

test('submitting valid code via livewire update endpoint authenticates user and redirects to dashboard', function () {
    $user = User::factory()->create(['role' => UserRole::EQUITIES]);
    $code = $user->generateTwoFactorCode();

    $challengeResponse = $this->withSession(['login.id' => $user->id])
        ->get(route('two-factor.challenge'));

    $challengeResponse->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', $challengeResponse->getContent(), $matches);
    expect($matches)->not->toBeEmpty();
    $snapshot = htmlspecialchars_decode($matches[1]);

    $updateResponse = $this->withSession(['login.id' => $user->id])
        ->withHeaders([
            'X-Livewire' => 'true',
        ])
        ->postJson(route('default-livewire.update'), [
            'components' => [
                [
                    'snapshot' => $snapshot,
                    'updates' => ['code' => $code],
                    'calls' => [
                        [
                            'path' => '',
                            'method' => 'verify',
                            'params' => [],
                        ],
                    ],
                ],
            ],
        ]);

    $updateResponse->assertOk();
    $data = $updateResponse->json();
    expect($data['components'][0]['effects']['redirect'])->toBe(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect($user->two_factor_code)->toBeNull();
    expect(session('login.id'))->toBeNull();
});
