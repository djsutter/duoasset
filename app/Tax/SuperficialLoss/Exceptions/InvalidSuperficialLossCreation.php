<?php

namespace App\Tax\SuperficialLoss\Exceptions;

final class InvalidSuperficialLossCreation extends SuperficialLossDomainException
{
    public static function negativeLoss(): self
    {
        return new self('Original loss must be greater than or equal to zero.');
    }

    public static function negativeUnits(): self
    {
        return new self('Original units must be greater than or equal to zero.');
    }

    public static function windowInvalid(): self
    {
        return new self('Window start must be before window end.');
    }

    public static function remainingExceedsOriginal(): self
    {
        return new self('Remaining loss or units cannot exceed original amounts.');
    }
}
