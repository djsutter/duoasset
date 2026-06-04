<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    public $timestamps = false;

    protected $table = 'price_history';

    protected $fillable = [
        'date',
        'currency',
        'usd',
        'cad',
    ];
}
