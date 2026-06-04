<?php

namespace App\Models;

use App\Casts\AssetQuantityCast;
use App\Casts\MoneyCast;
use App\Types\AssetQuantity;
use App\Types\Money;
use Illuminate\Database\Eloquent\Model;

class Lot extends Model
{
    protected $fillable = [
        'asset_code',
        'acquired_at',
        'original_quantity',
        'remaining_quantity',
        'original_acb_amount',
        'acb_event_id',
    ];

    protected $casts = [
        'acquired_at' => 'immutable_datetime',
        'original_quantity' => AssetQuantityCast::class,
        'remaining_quantity' => AssetQuantityCast::class,
        'original_acb_amount' => MoneyCast::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (Lot $lot) {
            if (! $lot->original_quantity->isPositive()) {
                throw new \LogicException('Lot quantity must be positive');
            }

            if (! $lot->original_acb_amount->isPositive()) {
                throw new \LogicException('Lot ACB must be positive');
            }
        });
    }

    /**
     * @deprecated use remaining_quantity field instead.
     */
    public function remainingQuantity(): AssetQuantity
    {
        $disposed = AssetQuantity::zero($this->asset_code);
        foreach (LotDisposition::where('lot_id', $this->id)->get() as $disposition) {
            $disposed = $disposed->add($disposition->disposed_quantity);
        }

        return $this->original_quantity->subtract($disposed);
    }

    public function unitCost(): Money
    {
        if ($this->original_quantity->isZero()) {
            throw new \LogicException("Lot {$this->id} has zero quantity");
        }

        return $this->original_acb_amount->divide($this->original_quantity->toDecimal());
    }
}
