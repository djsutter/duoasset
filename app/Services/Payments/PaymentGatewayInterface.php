<?php

namespace App\Services\Payments;

use App\Models\SubscriptionPayment;
use App\Models\User;

interface PaymentGatewayInterface
{
    /**
     * Create a card checkout session for a subscription payment.
     */
    public function createCheckoutSession(
        User $user,
        SubscriptionPayment $payment,
        string $successUrl,
        string $cancelUrl
    ): CheckoutSession;

    /**
     * Verify the webhook signature against payload and configured secret.
     */
    public function verifyWebhookSignature(string $payload, string $signature, ?string $secret = null): bool;

    /**
     * Parse webhook payload and extract standardized event data.
     */
    public function parseWebhookPayload(array $payload): WebhookEvent;
}
