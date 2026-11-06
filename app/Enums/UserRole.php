<?php

namespace App\Enums;

enum UserRole: string
{
    case GUEST = 'guest';
    case EQUITIES = 'equities';
    case CRYPTO = 'crypto';
    case FUTURES = 'futures';
    case FULL_SUBSCRIPTION = 'full_subscription';
    case ADMINISTRATOR = 'administrator';

    public function hasStockAccess(): bool
    {
        return match ($this) {
            self::GUEST, self::EQUITIES, self::FULL_SUBSCRIPTION, self::ADMINISTRATOR => true,
            self::CRYPTO, self::FUTURES => false,
        };
    }

    public function hasCryptoAccess(): bool
    {
        return match ($this) {
            self::CRYPTO, self::FULL_SUBSCRIPTION, self::ADMINISTRATOR => true,
            self::GUEST, self::EQUITIES, self::FUTURES => false,
        };
    }

    public function hasFuturesAccess(): bool
    {
        return match ($this) {
            self::FUTURES, self::FULL_SUBSCRIPTION, self::ADMINISTRATOR => true,
            self::GUEST, self::EQUITIES, self::CRYPTO => false,
        };
    }

    public function canToggleViewMode(): bool
    {
        return in_array($this, [self::FUTURES, self::FULL_SUBSCRIPTION, self::ADMINISTRATOR], true);
    }

    public function isHigherThanGuest(): bool
    {
        return $this !== self::GUEST;
    }

    public function label(): string
    {
        return match ($this) {
            self::GUEST => 'Guest',
            self::EQUITIES => 'Equities Subscriber',
            self::CRYPTO => 'Crypto Subscriber',
            self::FUTURES => 'Futures Subscriber',
            self::FULL_SUBSCRIPTION => 'Full Subscription',
            self::ADMINISTRATOR => 'Administrator',
        };
    }

    public static function resolve(self|string $role): self
    {
        if ($role instanceof self) {
            return $role;
        }

        $normalized = strtolower(trim($role));

        $direct = self::tryFrom($normalized);
        if ($direct !== null) {
            return $direct;
        }

        return match ($normalized) {
            'futures subscriber', 'futures_subscriber' => self::FUTURES,
            'equities subscriber', 'equities_subscriber', 'stocks' => self::EQUITIES,
            'crypto subscriber', 'crypto_subscriber' => self::CRYPTO,
            'full subscription', 'full' => self::FULL_SUBSCRIPTION,
            'admin', 'administrator' => self::ADMINISTRATOR,
            'guest' => self::GUEST,
            default => self::from($normalized),
        };
    }
}
