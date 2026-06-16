<?php

namespace App\Models;

use App\Casts\FiatMoneyCast;
use App\Enums\Currency;
use App\Enums\Exchange;
use App\Types\FiatMoney;
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
 * @property FiatMoney|null $last_price
 * @property FiatMoney|null $daily_change
 * @property int|null $daily_change_percent
 * @property int|null $volume
 * @property FiatMoney|null $market_cap
 * @property \Illuminate\Support\Carbon|null $last_checked_at
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
        'last_price',
        'daily_change',
        'daily_change_percent',
        'volume',
        'market_cap',
        'last_checked_at',
    ];

    protected $casts = [
        'exchange' => Exchange::class,
        'currency' => Currency::class,
        'last_price' => FiatMoneyCast::class.':currency',
        'daily_change' => FiatMoneyCast::class.':currency',
        'market_cap' => FiatMoneyCast::class.':currency',
        'daily_change_percent' => 'integer',
        'volume' => 'integer',
        'last_checked_at' => 'datetime',
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
