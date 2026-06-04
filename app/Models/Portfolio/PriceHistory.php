<?php

namespace App\Models\Portfolio;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    protected $connection = 'portfolio';

    protected $table = 'price_history';

    public $timestamps = false;
}
