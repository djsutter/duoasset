<?php

use App\Models\User;
use App\Notifications\TwoFactorAuthenticationCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Validate('required|string|size:6')]
    public string $code = '';

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirect(route('dashboard', absolute: false), navigate: true);
            return;
        }

        if (! Session::has('login.id') || ! User::where('id', Session::get('login.id'))->exists()) {
            Session::forget(['login.id', 'login.remember']);
            $this->redirect(route('login', absolute: false), navigate: true);
        }
    }

    public function verify(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        /** @var User|null $user */
        $user = User::find(Session::get('login.id'));

        if (! $user) {
            Session::forget(['login.id', 'login.remember']);
            $this->redirect(route('login', absolute: false), navigate: true);
            return;
        }

        if ($user->hasTwoFactorCodeExpired()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'code' => __('The two-factor authentication code has expired. Please request a new code.'),
            ]);
        }

        if (! $user->verifyTwoFactorCode($this->code)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'code' => __('The provided two-factor authentication code is invalid.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->resendThrottleKey());

        $remember = (bool) Session::get('login.remember', false);
        $user->resetTwoFactorCode();

        Session::forget(['login.id', 'login.remember']);
        Session::regenerate();

        Auth::login($user, $remember);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    public function resend(): void
    {
        $resendKey = $this->resendThrottleKey();

        if (RateLimiter::tooManyAttempts($resendKey, 2)) {
            $seconds = RateLimiter::availableIn($resendKey);
            throw ValidationException::withMessages([
                'code' => __('Too many resend attempts. Please try again in :seconds seconds.', ['seconds' => $seconds]),
            ]);
        }

        RateLimiter::hit($resendKey, 60);

        /** @var User|null $user */
        $user = User::find(Session::get('login.id'));

        if (! $user) {
            Session::forget(['login.id', 'login.remember']);
            $this->redirect(route('login', absolute: false), navigate: true);
            return;
        }

        $code = $user->generateTwoFactorCode();
        $user->notify(new TwoFactorAuthenticationCode($code));

        Session::flash('status', __('A new verification code has been sent to your email address.'));
    }

    public function cancel(): void
    {
        Session::forget(['login.id', 'login.remember']);
        $this->redirect(route('login', absolute: false), navigate: true);
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'code' => __('Too many attempts. Please try again in :seconds seconds.', [
                'seconds' => $seconds,
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return '2fa-verify|' . Session::get('login.id') . '|' . request()->ip();
    }

    protected function resendThrottleKey(): string
    {
        return '2fa-resend|' . Session::get('login.id') . '|' . request()->ip();
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Two-factor verification')" :description="__('Enter the 6-digit code sent to your email address to complete sign in.')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="verify" class="flex flex-col gap-6">
        <!-- 2FA Code -->
        <flux:input
            wire:model="code"
            :label="__('Verification Code')"
            type="text"
            inputmode="numeric"
            pattern="[0-9]{6}"
            maxlength="6"
            required
            autofocus
            autocomplete="one-time-code"
            placeholder="123456"
        />

        <div class="flex flex-col gap-3">
            <flux:button variant="primary" type="submit" class="w-full">
                {{ __('Verify and Log in') }}
            </flux:button>

            <div class="flex items-center justify-between text-sm">
                <button type="button" wire:click="resend" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white underline text-xs">
                    {{ __('Resend verification code') }}
                </button>

                <button type="button" wire:click="cancel" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white text-xs">
                    {{ __('Back to login') }}
                </button>
            </div>
        </div>
    </form>
</div>
