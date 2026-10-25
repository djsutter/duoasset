<?php

namespace App\Livewire\Subscription;

use App\Enums\UserRole;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Payments\SubscriptionPaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Index extends Component
{
    public ?string $checkout = null;
    public ?string $session_id = null;

    protected $queryString = [
        'checkout' => ['except' => ''],
        'session_id' => ['except' => ''],
    ];

    public function mount(): void
    {
        $this->checkout = request()->query('checkout');
        $this->session_id = request()->query('session_id');

        if ($this->checkout === 'success') {
            /** @var User|null $user */
            $user = Auth::user();
            if ($user) {
                /** @var SubscriptionPayment|null $recentPayment */
                $recentPayment = SubscriptionPayment::where('user_id', $user->id)
                    ->when($this->session_id, fn ($q) => $q->where('provider_session_id', $this->session_id))
                    ->latest()
                    ->first();

                if ($recentPayment && $recentPayment->isCompleted()) {
                    session()->flash('message', "Your payment has been confirmed! Your account is upgraded to {$user->role->label()}.");
                } else {
                    session()->flash('info', "Thank you! Your payment is being verified. Your subscription will be activated automatically once confirmation completes.");
                }
            }
        } elseif ($this->checkout === 'cancelled') {
            session()->flash('error', "Checkout was cancelled. No charges were made.");
        }
    }

    public function upgradeToPlan(string $plan, ?SubscriptionPaymentService $paymentService = null): void
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

        $paymentService = $paymentService ?? app(SubscriptionPaymentService::class);

        $successUrl = route('subscription', ['checkout' => 'success']);
        $cancelUrl = route('subscription', ['checkout' => 'cancelled']);

        $result = $paymentService->processUpgrade($user, $plan, $successUrl, $cancelUrl);

        if ($result->isAlreadyActive()) {
            session()->flash('message', $result->message);
            return;
        }

        if ($result->isCreditCompleted()) {
            $this->dispatch('view-mode-updated', mode: $user->activeViewMode());
            session()->flash('message', $result->message);
            return;
        }

        if ($result->isCheckoutRedirect() && $result->redirectUrl) {
            $this->redirect($result->redirectUrl);
        }
    }

    public function render(): View
    {
        /** @var User|null $user */
        $user = Auth::user();

        $pendingPayment = $user ? SubscriptionPayment::where('user_id', $user->id)
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->latest()
            ->first() : null;

        return view('livewire.subscription.index', [
            'user' => $user,
            'currentRole' => $user?->role ?? UserRole::GUEST,
            'creditBalance' => (float) ($user?->credit_balance ?? 0),
            'pendingPayment' => $pendingPayment,
        ])->layout('components.layouts.app', ['title' => 'Subscriptions & Upgrades - DuoAsset']);
    }
}
