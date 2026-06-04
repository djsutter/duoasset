<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletEntry extends Model
{
    protected $fillable = [
        'transaction_at',
        'transaction_id',
        'wallet_id',
        'entry_type',
        'amount',
        'currency',
        'foreign_amount',
        'foreign_currency',
    ];

    protected $casts = [
        'transaction_at' => 'datetime',
        'amount' => MoneyCast::class.':currency',
        'foreign_amount' => MoneyCast::class.':foreign_currency',
    ];

    public function isCrypto(): bool
    {
        return (bool) $this->foreign_amount;
    }

    public function isFiat(): bool
    {
        return $this->foreign_amount === null;
    }

    public function isNegative(): bool
    {
        return ($this->amount && $this->amount->isNegative()) || ($this->foreign_amount && $this->foreign_amount->isNegative());
    }

    public function isPositive(): bool
    {
        return ($this->amount && $this->amount->isPositive()) || ($this->foreign_amount && $this->foreign_amount->isPositive());
    }

    protected static function booted()
    {
        // Set the datetime when creating, defaulting to transaction datetime
        static::creating(function ($entry) {
            $entry->transaction_at ??= $entry->transaction?->transaction_at;
        });
    }

    public function setTransactionAtAttribute($value)
    {
        // Prevent changing the date after creation
        if ($this->exists) {
            return;
        }

        $this->attributes['transaction_at'] = $value;
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
