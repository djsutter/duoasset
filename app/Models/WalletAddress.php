<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletAddress extends Model
{
    /** @use HasFactory<\Database\Factories\WalletAddressFactory> */
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'address',
        'label',
    ];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}
