<?php

namespace App\Models;

use App\Casts\AssetQuantityCast;
use App\Casts\MoneyCast;
use App\Data\CostBasis\AcquisitionResultData;
use App\Data\CostBasis\DisposalResultData;
use App\Types\AssetQuantity;
use App\Types\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Asset extends Model
{
    /** @use HasFactory<\Database\Factories\AssetFactory> */
    use HasFactory;

    protected $fillable = [
        'asset_code',
        'precision',
        'quantity',
        'acb',
        'acb_currency',
        'total_proceeds',
        'total_cost',
        'last_transaction_at',
    ];

    protected $casts = [
        'quantity' => AssetQuantityCast::class,
        'acb' => MoneyCast::class.':acb_currency',
        'total_proceeds' => MoneyCast::class.':acb_currency',
        'total_cost' => MoneyCast::class.':acb_currency',
    ];

    public function applyAcquisition(AssetQuantity $quantity, Money $cost): AcquisitionResultData
    {
        if ($quantity->isNegative()) {
            throw new \InvalidArgumentException(
                "Acquisition quantity must be positive for {$this->asset_code}"
            );
        }

        $this->quantity = $this->quantity->add($quantity);
        $this->acb = $this->acb->add($cost);
        $this->total_cost = $this->total_cost->add($cost);

        return new AcquisitionResultData(
            quantity: $quantity,
            cost: $cost,
            total_cost: $cost,
            new_cum_qty: $this->quantity,
            new_cum_acb: $this->acb,
            acb_per_unit: $this->acbPerUnit(),
        );
    }

    public function applyDisposal(AssetQuantity $quantity, Money $proceeds): DisposalResultData
    {
        if ($quantity->isZero() || $quantity->isNegative()) {
            throw new \InvalidArgumentException(
                "Disposal quantity must be positive for {$this->asset_code}"
            );
        }

        if ($proceeds->isNegative()) {
            throw new \InvalidArgumentException(
                "Disposal proceeds must be positive for {$this->asset_code}"
            );
        }

        if ($this->quantity->isZero()) {
            throw new \LogicException(
                "Cannot dispose {$quantity->amount} {$this->asset_code} with zero ACB quantity"
            );
        }

        $quantityBefore = $this->quantity;
        $acbBefore = $this->acb;

        $ratio = bcdiv(
            $quantity->amount,
            $quantityBefore->amount,
            18
        );

        $acbAllocated = $acbBefore->multiply($ratio);

        $realizedGain = $proceeds->subtract($acbAllocated);

        $this->quantity = $quantityBefore->subtract($quantity);
        $this->acb = $acbBefore->subtract($acbAllocated);

        if ($this->quantity->isZero()) {
            $this->acb = Money::zero($this->acb_currency);
        }

        $this->total_proceeds = $this->total_proceeds->add($proceeds);

        return new DisposalResultData(
            quantity: $quantity,
            proceeds: $proceeds,
            acb_allocated: $acbAllocated,
            realized_gain: $realizedGain,
            new_cum_qty: $this->quantity,
            new_cum_acb: $this->acb,
        );
    }

    public function acbPerUnit(): Money
    {
        // Defensive: ensure we have Money objects available
        $quantity = $this->quantity ?? AssetQuantity::zero($this->asset_code);
        $acb = $this->acb ?? Money::zero($this->acb_currency);

        // If no quantity, return zero in the ACB currency
        if ($quantity->isZero()) {
            return Money::zero($this->acb_currency);
        }

        // acb is a Money in reporting currency; divide by numeric quantity
        return $acb->divide($quantity->amount);
    }

    public function currency(): HasOne
    {
        return $this->hasOne(Currency::class, 'currency_code', 'asset_code');
    }

    public function isBaseCurrency(): bool
    {
        return $this->asset_code === 'CAD';
    }
}
