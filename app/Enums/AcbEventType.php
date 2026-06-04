<?php

namespace App\Enums;

use App\Models\WalletEntry;

/**
 * The canonical event types that the ACB engine consumes.
 */
enum AcbEventType: string
{
    case Acquisition = 'acquisition';
    case Disposal = 'disposal';
    case TransferFee = 'transfer_fee';
    case Adjustment = 'adjustment';

    public function createsLot(): bool
    {
        return $this === self::Acquisition;
    }

    /**
     * Convert a WalletEntry into an ACB event type.
     */
    public static function classify(WalletEntry $entry): self
    {
        return match ($entry->entry_type) {
            'in' => self::Acquisition,
            'out' => self::Disposal,
            'transfer_fee' => self::TransferFee,
            default => throw new \LogicException("Invalid entry type: {$entry->entry_type}"),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Acquisition => 'ACQUISITION',
            self::Disposal => 'DISPOSITION',
            self::TransferFee => 'TRANSFER-FEE',
            self::Adjustment => 'ADJUSTMENT',
        };
    }

    public function quantityDirection(): int
    {
        return match ($this) {
            self::Acquisition => +1,
            self::Disposal, self::TransferFee => -1,
            default => 0,
        };
    }

    public function affectsAcb(): bool
    {
        return match ($this) {
            self::Acquisition,
            self::Disposal,
            self::Adjustment => true,
            default => false,
        };
    }
}
