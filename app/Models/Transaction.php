<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property Collection<int, WalletEntry> $entries
 */
class Transaction extends Model
{
    protected $fillable = [
        'transaction_at',
        'tx_type',
        'description',
        'address',
        'is_income',
        'valuation_status',
    ];

    protected $casts = [
        'transaction_at' => 'datetime',
        'tx_type' => TransactionType::class,
    ];

    /**
     * @return HasMany<WalletEntry>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    public function isValued(): bool
    {
        return $this->valuation_status === 'done';
    }
}
