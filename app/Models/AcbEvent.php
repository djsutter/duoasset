<?php

namespace App\Models;

use App\Casts\AssetQuantityCast;
use App\Casts\MoneyCast;
use App\Enums\AcbAdjustmentReason;
use App\Enums\AcbEventType;
use App\Enums\TaxPoolLedgerEntryType;
use Illuminate\Database\Eloquent\Model;

class AcbEvent extends Model
{
    protected $fillable = [
        'asset_code',
        'tx_id',
        'event_at',
        'event_type',
        'quantity',
        'cost_amount',
        'proceeds',
        'adjustment_reason',
        'source_superficial_loss_event_id',
    ];

    protected $casts = [
        'event_at' => 'datetime',
        'event_type' => AcbEventType::class,
        'quantity' => AssetQuantityCast::class,
        'cost_amount' => MoneyCast::class, // Uses reporting currency
        'proceeds' => MoneyCast::class, // Uses reporting currency
        'adjustment_reason' => AcbAdjustmentReason::class,
    ];

    public function getTaxEventType(): TaxPoolLedgerEntryType
    {
        return match ($this->event_type) {
            AcbEventType::Acquisition => TaxPoolLedgerEntryType::Acquisition,
            AcbEventType::Disposal, AcbEventType::TransferFee => TaxPoolLedgerEntryType::Disposition,
            default => throw new \LogicException("Unknown AcbEventType {$this->event_type}")
        };
    }

    public function isAcquisition(): bool
    {
        return $this->event_type === AcbEventType::Acquisition;
    }

    public function isDisposition(): bool
    {
        return $this->event_type === AcbEventType::Disposal;
    }

    public function isTransferFee(): bool
    {
        return $this->event_type === AcbEventType::TransferFee;
    }

    public function scopeOrderedDeterministically($query)
    {
        return $query
            ->orderBy('event_at', 'asc')
            ->orderBy('id', 'asc');
    }
}
