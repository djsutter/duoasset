<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\Exchange;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $symbol
 * @property Exchange $exchange
 * @property Currency $currency
 * @property string $company_name
 * @property int $sector_id
 * @property int $industry_id
 * @property int $sub_industry_id
 */
class Stock extends Model
{
    /** @use HasFactory<\Database\Factories\StockFactory> */
    use HasFactory;

    protected $fillable = [
        'symbol',
        'exchange',
        'currency',
        'company_name',
        'sector_id',
        'industry_id',
        'sub_industry_id',
    ];

    protected $casts = [
        'exchange' => Exchange::class,
        'currency' => Currency::class,
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    public function subIndustry(): BelongsTo
    {
        return $this->belongsTo(SubIndustry::class);
    }

    public function watchlistItems(): HasMany
    {
        return $this->hasMany(WatchlistItem::class);
    }
}
