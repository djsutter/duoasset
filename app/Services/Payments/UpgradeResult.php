<?php

namespace App\Services\Payments;

use App\Enums\UserRole;
use App\Models\SubscriptionPayment;

readonly class UpgradeResult
{
    public function __construct(
        public readonly string $type,
        public readonly ?SubscriptionPayment $payment = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?UserRole $role = null,
        public readonly ?string $message = null
    ) {}

    public static function creditCompleted(SubscriptionPayment $payment, UserRole $role): self
    {
        return new self(
            type: 'credit_completed',
            payment: $payment,
            role: $role,
            message: "Congratulations! Your account has been upgraded to {$role->label()} using your account credits."
        );
    }

    public static function checkoutRedirect(SubscriptionPayment $payment, string $redirectUrl): self
    {
        return new self(
            type: 'checkout_redirect',
            payment: $payment,
            redirectUrl: $redirectUrl
        );
    }

    public static function alreadyActive(UserRole $role): self
    {
        return new self(
            type: 'already_active',
            role: $role,
            message: "You are already active on the {$role->label()} plan."
        );
    }

    public function isCreditCompleted(): bool
    {
        return $this->type === 'credit_completed';
    }

    public function isCheckoutRedirect(): bool
    {
        return $this->type === 'checkout_redirect';
    }

    public function isAlreadyActive(): bool
    {
        return $this->type === 'already_active';
    }
}
