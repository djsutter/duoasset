<?php

namespace App\Tax\SuperficialLoss\Exceptions;

use App\Tax\SuperficialLoss\Domain\PendingSuperficialLossStatus;

final class InvalidSuperficialLossTransition extends SuperficialLossDomainException
{
    public static function from(
        PendingSuperficialLossStatus $from,
        string $action
    ): self {
        return new self(
            "Cannot {$action} pending superficial loss in state {$from->value}."
        );
    }
}
