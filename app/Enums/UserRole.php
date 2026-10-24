<?php

namespace App\Enums;

enum UserRole: string
{
    case GUEST = 'guest';
    case EQUITIES = 'equities';
    case CRYPTO = 'crypto';
    case FULL_SUBSCRIPTION = 'full_subscription';
    case ADMINISTRATOR = 'administrator';

    public function hasStockAccess(): bool
    {
        return match ($this) {
            self::GUEST, self::EQUITIES, self::FULL_SUBSCRIPTION, self::ADMINISTRATOR => true,
            self::CRYPTO => false,
        };
    }

    public function hasCryptoAccess(): bool
    {
        return match ($this) {
            self::CRYPTO, self::FULL_SUBSCRIPTION, self::ADMINISTRATOR => true,
            self::GUEST, self::EQUITIES => false,
        };
    }

    public function canToggleViewMode(): bool
    {
        return in_array($this, [self::FULL_SUBSCRIPTION, self::ADMINISTRATOR], true);
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
            self::FULL_SUBSCRIPTION => 'Full Subscription',
            self::ADMINISTRATOR => 'Administrator',
        };
    }
}
