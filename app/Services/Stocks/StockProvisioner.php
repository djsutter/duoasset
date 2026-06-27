<?php

namespace App\Services\Stocks;

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use Illuminate\Support\Facades\DB;

/**
 * Find-or-create a basic Stock row for a given symbol. Used by the EPS
 * surprise scanner so that any symbol surfaced by the scanner has a
 * Stock entry available for watchlists / lookups.
 */
class StockProvisioner
{
    /**
     * @param  string  $symbol       Ticker symbol (will be uppercased / trimmed).
     * @param  string|null  $exchange   Exchange short name (e.g. NYSE, NASDAQ, TSX, TSXV).
     * @param  string|null  $companyName Optional company name from provider profile.
     */
    public function findOrCreate(string $symbol, ?string $exchange = null, ?string $companyName = null): Stock
    {
        $symbol = strtoupper(trim($symbol));

        $existing = Stock::query()->where('symbol', $symbol)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($symbol, $exchange, $companyName) {
            $exchangeEnum = Exchange::tryFrom((string) $exchange) ?? Exchange::NYSE;
            $currencyEnum = match ($exchangeEnum) {
                Exchange::TSX, Exchange::TSXV => Currency::CAD,
                default => Currency::USD,
            };

            $sector = Sector::query()->orderBy('id')->first()
                ?? Sector::create(['name' => 'Unknown', 'slug' => 'unknown']);
            $industry = Industry::query()->where('sector_id', $sector->id)->orderBy('id')->first()
                ?? Industry::create([
                    'name' => 'Unknown',
                    'slug' => 'unknown-'.$sector->id,
                    'sector_id' => $sector->id,
                ]);
            $subIndustry = SubIndustry::query()->where('industry_id', $industry->id)->orderBy('id')->first()
                ?? SubIndustry::create([
                    'name' => 'Unknown',
                    'slug' => 'unknown-'.$industry->id,
                    'industry_id' => $industry->id,
                ]);

            return Stock::create([
                'symbol' => $symbol,
                'exchange' => $exchangeEnum,
                'currency' => $currencyEnum,
                'company_name' => $companyName ?: $symbol,
                'sector_id' => $sector->id,
                'industry_id' => $industry->id,
                'sub_industry_id' => $subIndustry->id,
            ]);
        });
    }
}
