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
 * @property FiatMoney|null $market_cap Computed: last_price × shares_outstanding (falls back to stored column).
 * @property int|null $shares_outstanding
 * @property int|null $float_shares
 * @property string|null $free_float
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
        'shares_outstanding',
        'float_shares',
        'free_float',
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
        'shares_outstanding' => 'integer',
        'float_shares' => 'integer',
        'free_float' => 'decimal:4',
        'last_checked_at' => 'datetime',
    ];

    /**
     * Computed market cap accessor: last_price × shares_outstanding.
     *
     * Returns a FiatMoney value (consistent with the stored cast).
     * Falls back to the raw stored `market_cap` column when either
     * the price or shares-outstanding inputs are missing.
     */
    public function getMarketCapAttribute(): ?FiatMoney
    {
        $shares = $this->attributes['shares_outstanding'] ?? null;
        $shares = is_numeric($shares) ? (int) $shares : null;

        $price = $this->last_price;

        if ($price instanceof FiatMoney && $shares !== null && $shares > 0) {
            // FiatMoney stores `minor` in 10^4 units. Multiplying by a
            // whole-share count preserves that scale, so the resulting
            // amount is still expressed in the same minor units.
            return FiatMoney::fromMinorUnits(
                (int) ($price->minor * $shares),
                $price->currency,
            );
        }

        $stored = $this->attributes['market_cap'] ?? null;
        if ($stored === null || $stored === '') {
            return null;
        }

        $currency = $this->currency?->value ?? ($price?->currency ?? 'USD');

        return FiatMoney::fromMinorUnits((int) $stored, $currency);
    }

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
