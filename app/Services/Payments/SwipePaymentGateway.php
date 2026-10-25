<?php

namespace App\Services\Payments;

use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Str;

class SwipePaymentGateway implements PaymentGatewayInterface
{
    /**
     * Create a card checkout session for a subscription payment.
     */
    public function createCheckoutSession(
        User $user,
        SubscriptionPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): CheckoutSession {
        $sessionId = 'swipe_sess_'.Str::random(24);
        $paymentId = 'swipe_pi_'.Str::random(24);

        $baseUrl = rtrim((string) config('services.payment.swipe.base_url', 'https://checkout.swipe.example.com'), '/');
        $checkoutUrl = $baseUrl.'/pay/'.$sessionId;

        $payload = [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'customer_email' => $user->email,
            'client_reference_id' => (string) $user->id,
            'payment_intent' => $paymentId,
            'amount_total' => (int) round(((float) $payment->amount_due) * 100),
            'currency' => strtolower($payment->currency ?: 'cad'),
            'status' => 'open',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'user_id' => $user->id,
                'plan' => $payment->plan,
                'payment_id' => $payment->id,
            ],
            'url' => $checkoutUrl,
        ];

        return new CheckoutSession(
            sessionId: $sessionId,
            checkoutUrl: $checkoutUrl,
            paymentId: $paymentId,
            raw: $payload
        );
    }

    /**
     * Verify the webhook signature against payload and configured secret.
     */
    public function verifyWebhookSignature(string $payload, string $signature, ?string $secret = null): bool
    {
        $secret = $secret ?? (string) config('services.payment.swipe.webhook_secret', 'whsec_swipe_test_secret');

        if ($secret === '' || $signature === '') {
            return false;
        }

        // Stripe/Swipe formatted header: t=...,v1=...
        if (str_contains($signature, 'v1=')) {
            preg_match('/t=(\d+)/', $signature, $timestampMatches);
            preg_match('/v1=([a-fA-F0-9]+)/', $signature, $sigMatches);

            if (empty($sigMatches[1])) {
                return false;
            }

            $timestamp = $timestampMatches[1] ?? '';
            $actualHash = $sigMatches[1];

            // Primary: hash of timestamp . '.' . payload
            $expectedWithTs = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
            if (hash_equals($expectedWithTs, $actualHash)) {
                return true;
            }

            // Fallback: direct payload hash
            $expectedDirect = hash_hmac('sha256', $payload, $secret);

            return hash_equals($expectedDirect, $actualHash);
        }

        // Direct raw HMAC sha256 hex signature
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Parse webhook payload and extract standardized event data.
     */
    public function parseWebhookPayload(array $payload): WebhookEvent
    {
        $eventType = $payload['type'] ?? $payload['event'] ?? 'unknown';
        $object = $payload['data']['object'] ?? $payload;

        $sessionId = $object['id'] ?? $object['session_id'] ?? null;
        $paymentId = $object['payment_intent'] ?? $object['payment_id'] ?? null;
        $metadata = $object['metadata'] ?? [];

        $plan = $metadata['plan'] ?? $object['plan'] ?? null;
        $userId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : ($object['user_id'] ?? null);

        return new WebhookEvent(
            eventType: $eventType,
            sessionId: $sessionId,
            paymentId: $paymentId,
            plan: $plan,
            userId: $userId ? (int) $userId : null,
            metadata: is_array($metadata) ? $metadata : [],
            raw: $payload
        );
    }
}
