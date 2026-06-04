<?php

namespace App\Models\Invest;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holding extends Model
{
    protected $connection = 'invest';

    public $timestamps = false;

    protected $fillable = [
        'amount',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
