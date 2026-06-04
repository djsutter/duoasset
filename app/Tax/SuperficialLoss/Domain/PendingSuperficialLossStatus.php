<?php

namespace App\Tax\SuperficialLoss\Domain;

enum PendingSuperficialLossStatus: string
{
    case Pending = 'pending';
    case PartiallyDenied = 'partially_denied';
    case FullyDenied = 'fully_denied';
    case Expired = 'expired';

    public function canDeny(): bool
    {
        return match ($this) {
            self::Pending,
            self::PartiallyDenied => true,

            self::FullyDenied,
            self::Expired => false,
        };
    }

    public function canExpire(): bool
    {
        return match ($this) {
            self::Pending,
            self::PartiallyDenied => true,

            self::FullyDenied,
            self::Expired => false,
        };
    }
}
