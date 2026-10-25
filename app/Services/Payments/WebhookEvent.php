<?php

namespace App\Services\Payments;

readonly class WebhookEvent
{
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $sessionId,
        public readonly ?string $paymentId = null,
        public readonly ?string $plan = null,
        public readonly ?int $userId = null,
        public readonly array $metadata = [],
        public readonly array $raw = []
    ) {}
}
