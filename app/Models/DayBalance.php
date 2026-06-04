<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class DayBalance extends Model
{
    protected $fillable = [
        'wallet_id',
        'date',
        'balance',
        'currency',
    ];

    protected $casts = [
        'date' => 'date',
        'balance' => MoneyCast::class.':currency',
    ];
}
