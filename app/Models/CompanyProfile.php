<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Persisted company profile metadata.
 *
 * @property int $id
 * @property string $symbol
 * @property string|null $company_name
 * @property string|null $exchange
 * @property string|null $currency
 * @property string|null $country
 * @property string|null $sector
 * @property string|null $industry
 * @property string|null $sub_industry
 * @property int|null $market_cap
 * @property string|null $price
 * @property int|null $shares_outstanding
 * @property int|null $float_shares
 * @property float|null $free_float
 * @property bool|null $is_actively_trading
 * @property bool|null $is_etf
 * @property bool|null $is_fund
 * @property bool|null $is_adr
 * @property string|null $type
 * @property \Illuminate\Support\Carbon|null $retrieved_at
 */
class CompanyProfile extends Model
{
    protected $fillable = [
        'symbol',
        'company_name',
        'exchange',
        'currency',
        'country',
        'sector',
        'industry',
        'sub_industry',
        'market_cap',
        'price',
        'shares_outstanding',
        'float_shares',
        'free_float',
        'is_actively_trading',
        'is_etf',
        'is_fund',
        'is_adr',
        'type',
        'retrieved_at',
    ];

    protected $casts = [
        'market_cap' => 'integer',
        'price' => 'decimal:6',
        'shares_outstanding' => 'integer',
        'float_shares' => 'integer',
        'free_float' => 'float',
        'is_actively_trading' => 'boolean',
        'is_etf' => 'boolean',
        'is_fund' => 'boolean',
        'is_adr' => 'boolean',
        'retrieved_at' => 'datetime',
    ];

    /**
     * Filter an attribute array to only include instrument metadata columns
     * when those columns exist on company_profiles.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function filterPersistedAttributes(array $attributes): array
    {
        $metadataColumns = ['is_actively_trading', 'is_etf', 'is_fund', 'is_adr', 'type'];
        $hasAnyMetadata = array_intersect_key($attributes, array_flip($metadataColumns));
        if (empty($hasAnyMetadata)) {
            return $attributes;
        }

        try {
            if (! Schema::hasTable('company_profiles')) {
                return $attributes;
            }

            foreach ($metadataColumns as $column) {
                if (array_key_exists($column, $attributes) && ! Schema::hasColumn('company_profiles', $column)) {
                    unset($attributes[$column]);
                }
            }
        } catch (Throwable) {
        }

        return $attributes;
    }
}
