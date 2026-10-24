<?php

namespace App\Livewire\Subscription;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public function upgradeToPlan(string $plan): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            abort(401, 'Please sign in to select a subscription plan.');
        }

        if (! in_array($plan, ['equities', 'crypto', 'full_subscription'], true)) {
            abort(400, 'Invalid subscription tier.');
        }

        $targetRole = UserRole::from($plan);

        if ($user->role === $targetRole) {
            session()->flash('message', "You are already active on the {$targetRole->label()} plan.");
            return;
        }

        $planPrices = [
            'equities' => 29.00,
            'crypto' => 29.00,
            'full_subscription' => 49.00,
        ];

        $price = $planPrices[$plan] ?? 29.00;
        $creditBalance = (float) ($user->credit_balance ?? 0);

        if ($creditBalance > 0) {
            $deduction = min($creditBalance, $price);
            $user->applyCredit(
                -1 * $deduction,
                "Applied credit toward {$targetRole->label()} upgrade",
                null,
                'subscription_payment'
            );
        }

        $user->assignRole($targetRole);

        $this->dispatch('view-mode-updated', mode: $user->activeViewMode());
        session()->flash('message', "Congratulations! Your account has been upgraded to {$targetRole->label()}. You now have unrestricted access.");
    }

    public function render()
    {
        /** @var User|null $user */
        $user = Auth::user();

        return view('livewire.subscription.index', [
            'user' => $user,
            'currentRole' => $user?->role ?? UserRole::GUEST,
            'creditBalance' => (float) ($user?->credit_balance ?? 0),
        ])->layout('components.layouts.app', ['title' => 'Subscriptions & Upgrades - DuoAsset']);
    }
}
