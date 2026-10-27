<?php

use App\Models\User;
use App\Notifications\TwoFactorAuthenticationCode;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt as LivewireVolt;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users initiate two-factor authentication on valid login', function () {
    Notification::fake();

    $user = User::factory()->create();

    $response = LivewireVolt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('two-factor.challenge', absolute: false));

    $this->assertGuest();
    $this->assertEquals($user->id, session('login.id'));
    Notification::assertSentTo($user, TwoFactorAuthenticationCode::class);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = LivewireVolt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login');

    $response->assertHasErrors('email');

    $this->assertGuest();
});

test('unactivated users can not authenticate', function () {
    $user = User::factory()->unverified()->create();

    $response = LivewireVolt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login');

    $response->assertHasErrors('email');

    $this->assertGuest();
    $this->assertNull(session('login.id'));
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect('/');

    $this->assertGuest();
});
