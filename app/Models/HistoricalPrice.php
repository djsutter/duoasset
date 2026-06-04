<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoricalPrice extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'date',
        'currency',
        'price',
    ];
}
