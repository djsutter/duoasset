<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    Notification::fake();

    $response = Volt::test('auth.register')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register');

    $response
        ->assertHasNoErrors()
        ->assertRedirect(route('login', absolute: false));

    $this->assertGuest();

    $user = User::where('email', 'test@example.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::GUEST)
        ->and($user->view_mode)->toBe('stocks')
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->activation_token)->not->toBeNull();

    Notification::assertSentTo($user, AccountActivationNotification::class, function ($notification) use ($user) {
        return hash('sha256', $notification->token) === $user->activation_token;
    });
});
