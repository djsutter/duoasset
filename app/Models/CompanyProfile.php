<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'retrieved_at',
    ];

    protected $casts = [
        'market_cap' => 'integer',
        'price' => 'decimal:6',
        'shares_outstanding' => 'integer',
        'float_shares' => 'integer',
        'free_float' => 'float',
        'retrieved_at' => 'datetime',
    ];
}
