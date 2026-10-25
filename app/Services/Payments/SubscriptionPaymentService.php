<?php

namespace App\Services\Payments;

use App\Enums\UserRole;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionPaymentService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway
    ) {}

    /**
     * Attempt or initiate an upgrade to a plan for a user.
     */
    public function processUpgrade(User $user, string $plan, string $successUrl, string $cancelUrl): UpgradeResult
    {
        if (! in_array($plan, ['equities', 'crypto', 'full_subscription'], true)) {
            throw new \InvalidArgumentException('Invalid subscription tier.');
        }

        $targetRole = UserRole::from($plan);

        if ($user->role === $targetRole) {
            return UpgradeResult::alreadyActive($targetRole);
        }

        $price = SubscriptionPayment::PLAN_PRICES[$plan] ?? 29.00;
        $creditBalance = (float) ($user->credit_balance ?? 0);

        // Case 1: User's credits fully cover the plan price (internal credit upgrade)
        if ($creditBalance >= $price) {
            return DB::transaction(function () use ($user, $plan, $targetRole, $price) {
                // Deduct from credits
                $user->applyCredit(
                    -1 * $price,
                    "Applied credit toward {$targetRole->label()} upgrade",
                    null,
                    'subscription_payment'
                );

                // Create completed payment record
                /** @var SubscriptionPayment $payment */
                $payment = SubscriptionPayment::create([
                    'user_id' => $user->id,
                    'plan' => $plan,
                    'status' => SubscriptionPayment::STATUS_COMPLETED,
                    'amount' => $price,
                    'credit_applied' => $price,
                    'amount_due' => 0.00,
                    'currency' => config('services.payment.currency', 'CAD'),
                    'provider' => SubscriptionPayment::PROVIDER_INTERNAL_CREDIT,
                    'provider_payment_id' => 'credit_'.Str::random(16),
                    'fulfilled_at' => now(),
                ]);

                // Assign role
                $user->assignRole($targetRole);

                Log::info('subscription.credit_upgrade_completed', [
                    'user_id' => $user->id,
                    'plan' => $plan,
                    'amount' => $price,
                    'credit_applied' => $price,
                    'payment_id' => $payment->id,
                ]);

                return UpgradeResult::creditCompleted($payment, $targetRole);
            });
        }

        // Case 2: User must pay remaining balance via external card checkout
        $creditApplied = $creditBalance > 0 ? min($creditBalance, $price) : 0.00;
        $amountDue = round($price - $creditApplied, 2);

        /** @var SubscriptionPayment $payment */
        $payment = SubscriptionPayment::create([
            'user_id' => $user->id,
            'plan' => $plan,
            'status' => SubscriptionPayment::STATUS_PENDING,
            'amount' => $price,
            'credit_applied' => $creditApplied,
            'amount_due' => $amountDue,
            'currency' => config('services.payment.currency', 'CAD'),
            'provider' => config('services.payment.provider', 'swipe'),
        ]);

        $checkoutSession = $this->gateway->createCheckoutSession(
            $user,
            $payment,
            $successUrl,
            $cancelUrl
        );

        $payment->update([
            'provider_session_id' => $checkoutSession->sessionId,
            'provider_payment_id' => $checkoutSession->paymentId,
            'checkout_url' => $checkoutSession->checkoutUrl,
            'metadata' => array_merge($payment->metadata ?? [], [
                'checkout_session' => $checkoutSession->raw,
            ]),
        ]);

        Log::info('subscription.checkout_initiated', [
            'user_id' => $user->id,
            'plan' => $plan,
            'amount' => $price,
            'credit_applied' => $creditApplied,
            'amount_due' => $amountDue,
            'session_id' => $checkoutSession->sessionId,
            'payment_id' => $payment->id,
        ]);

        return UpgradeResult::checkoutRedirect($payment, $checkoutSession->checkoutUrl);
    }

    /**
     * Fulfill a payment idempotently and activate user role.
     */
    public function fulfillPayment(SubscriptionPayment $payment, ?string $providerPaymentId = null, array $metadata = []): bool
    {
        return DB::transaction(function () use ($payment, $providerPaymentId, $metadata) {
            // Reload with lock for concurrency safety
            /** @var SubscriptionPayment|null $lockedPayment */
            $lockedPayment = SubscriptionPayment::lockForUpdate()->find($payment->id);

            if (! $lockedPayment || $lockedPayment->status === SubscriptionPayment::STATUS_COMPLETED || $lockedPayment->fulfilled_at !== null) {
                Log::info('subscription.payment_already_fulfilled', [
                    'payment_id' => $lockedPayment?->id,
                    'status' => $lockedPayment?->status,
                    'fulfilled_at' => $lockedPayment?->fulfilled_at?->toIso8601String(),
                ]);

                return true; // Idempotent success
            }

            /** @var User $user */
            $user = $lockedPayment->user;
            $targetRole = UserRole::from($lockedPayment->plan);

            // Deduct credits if applied and not yet deducted
            if ((float) $lockedPayment->credit_applied > 0) {
                $actualDeduction = min((float) ($user->credit_balance ?? 0), (float) $lockedPayment->credit_applied);
                if ($actualDeduction > 0) {
                    $user->applyCredit(
                        -1 * $actualDeduction,
                        "Applied credit toward {$targetRole->label()} upgrade",
                        null,
                        'subscription_payment'
                    );
                }
            }

            // Assign role
            $user->assignRole($targetRole);

            // Update payment record
            $lockedPayment->update([
                'status' => SubscriptionPayment::STATUS_COMPLETED,
                'provider_payment_id' => $providerPaymentId ?: $lockedPayment->provider_payment_id,
                'fulfilled_at' => now(),
                'metadata' => array_merge($lockedPayment->metadata ?? [], $metadata),
            ]);

            Log::info('subscription.payment_fulfilled', [
                'user_id' => $user->id,
                'plan' => $lockedPayment->plan,
                'payment_id' => $lockedPayment->id,
                'provider_payment_id' => $lockedPayment->provider_payment_id,
            ]);

            return true;
        });
    }
}
