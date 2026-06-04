<?php

namespace App\Tax\SuperficialLoss\Exceptions;

use App\Types\Money;

final class ExcessiveLossDenial extends SuperficialLossDomainException
{
    public static function attempted(Money $attempted, Money $remaining): self
    {
        return new self(
            "Attempted to deny {$attempted->format()}, but only {$remaining->format()} remains."
        );
    }
}
