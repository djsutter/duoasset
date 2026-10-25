<?php

namespace App\Services\Payments;

readonly class CheckoutSession
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $checkoutUrl,
        public readonly ?string $paymentId = null,
        public readonly array $raw = []
    ) {}
}
