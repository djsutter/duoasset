<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Types\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageEntry extends Model
{
    protected $fillable = [
        'stage_transaction_id',
        'tx_at',
        'atomic_type',
        'wallet_id',
        'amount',
        'currency',
        'foreign_amount',
        'foreign_currency',
    ];

    protected $casts = [
        'tx_at' => 'datetime',
        'amount' => MoneyCast::class.':currency',
        'foreign_amount' => MoneyCast::class.':foreign_currency',
    ];

    public function amountFormatted(): ?string
    {
        if ($this->foreign_amount) {
            return $this->foreign_amount->format().' '.$this->foreign_currency;
        }
        if ($this->amount) {
            return $this->amount->format().' '.$this->currency;
        }

        return null;
    }

    public function effectiveAmount(): ?Money
    {
        return $this->foreign_amount ?? $this->amount;
    }

    public function effectiveCurrency(): ?string
    {
        return $this->foreign_currency ?? $this->currency;
    }

    public function stageTransaction(): BelongsTo
    {
        return $this->belongsTo(StageTransaction::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
