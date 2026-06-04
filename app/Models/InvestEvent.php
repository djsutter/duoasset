<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestEvent extends Model
{
    protected $fillable = [
        'date',
        'wallet_id',
        'prev_balance',
        'new_balance',
        'change',
        'currency',
        'message',
    ];

    protected $casts = [
        'date' => 'date',
        'prev_balance' => MoneyCast::class.':currency',
        'new_balance' => MoneyCast::class.':currency',
        'change' => MoneyCast::class.':currency',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
