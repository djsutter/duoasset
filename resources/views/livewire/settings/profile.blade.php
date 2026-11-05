<?php

use App\Models\NotificationOptOut;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';
    public bool $email_eps_surprise_digest = true;
    public bool $email_setup_scoring_digest = false;
    public bool $notify_eps_earnings = true;
    public bool $notify_eps_revisions = true;
    public bool $notify_stock_buy_setup = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->email_eps_surprise_digest = (bool) ($user->email_eps_surprise_digest ?? $user->notify_eps_earnings);
        $this->notify_eps_earnings = $this->email_eps_surprise_digest;
        $this->notify_eps_revisions = (bool) $user->notify_eps_revisions;
        $this->email_setup_scoring_digest = (bool) ($user->email_setup_scoring_digest ?? $user->notify_stock_buy_setup);
        $this->notify_stock_buy_setup = $this->email_setup_scoring_digest;
    }

    public function updatedEmailEpsSurpriseDigest(bool $value): void
    {
        $this->notify_eps_earnings = $value;
    }

    public function updatedNotifyEpsEarnings(bool $value): void
    {
        $this->email_eps_surprise_digest = $value;
    }

    public function updatedEmailSetupScoringDigest(bool $value): void
    {
        $this->notify_stock_buy_setup = $value;
    }

    public function updatedNotifyStockBuySetup(bool $value): void
    {
        $this->email_setup_scoring_digest = $value;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id)
            ],
            'email_eps_surprise_digest' => ['boolean'],
            'email_setup_scoring_digest' => ['boolean'],
            'notify_eps_earnings' => ['boolean'],
            'notify_eps_revisions' => ['boolean'],
            'notify_stock_buy_setup' => ['boolean'],
        ]);

        $epsEnabled = (bool) $validated['email_eps_surprise_digest'];
        $setupEnabled = (bool) $validated['email_setup_scoring_digest'];

        $validated['email_eps_surprise_digest'] = $epsEnabled;
        $validated['notify_eps_earnings'] = $epsEnabled;
        $validated['email_setup_scoring_digest'] = $setupEnabled;
        $validated['notify_stock_buy_setup'] = $setupEnabled;

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        // Keep NotificationOptOut rows aligned when the user explicitly updates preferences in Profile.
        $earningsOptOut = NotificationOptOut::where('email', strtolower(trim($user->email)))
            ->where('item_type', NotificationOptOut::TYPE_EARNINGS)
            ->first();
        if ($earningsOptOut) {
            $epsEnabled ? $earningsOptOut->optIn() : $earningsOptOut->optOut();
        }

        $setupOptOut = NotificationOptOut::where('email', strtolower(trim($user->email)))
            ->where('item_type', NotificationOptOut::TYPE_BUY_SETUP)
            ->first();
        if ($setupOptOut) {
            $setupEnabled ? $setupOptOut->optIn() : $setupOptOut->optOut();
        }

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail &&! auth()->user()->hasVerifiedEmail())
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <div class="space-y-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <p class="text-sm font-medium">{{ __('Email Notifications') }}</p>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model.live="email_eps_surprise_digest" class="mt-0.5 rounded border-zinc-300" />
                    <div>
                        <span class="font-medium block">{{ __('EPS Surprise Notifications') }}</span>
                        <span class="text-xs text-zinc-500 block">{{ __('Receive personalized summaries of EPS surprises, misses, and top-scoring earnings opportunities.') }}</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model.live="email_setup_scoring_digest" class="mt-0.5 rounded border-zinc-300" />
                    <div>
                        <span class="font-medium block">{{ __('Setup Scoring Notifications') }}</span>
                        <span class="text-xs text-zinc-500 block">{{ __('Receive personalized summaries of setup score improvements, deteriorations, and other significant scoring notices.') }}</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="notify_eps_revisions" class="mt-0.5 rounded border-zinc-300" />
                    <div>
                        <span class="font-medium block">{{ __('EPS Revision Notifications') }}</span>
                        <span class="text-xs text-zinc-500 block">{{ __('EPS Revision alerts (analyst Target Raised / Cut)') }}</span>
                    </div>
                </label>

                <p class="text-xs text-zinc-500">
                    {{ __('One personalized digest email is sent per completed scanner run for each enabled notification category.') }}
                </p>
            </div>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <livewire:settings.delete-user-form />
    </x-settings.layout>
</section>
