<?php

namespace App\Services\Stocks;

use App\Enums\Currency;
use App\Enums\Exchange;
use App\Models\Industry;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\SubIndustry;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Find-or-create a Stock row with proper classification (Sector, Industry, SubIndustry)
 * for a given symbol. Used across scanners and watchlists.
 */
class StockProvisioner
{
    public function __construct(
        protected ?MarketDataProvider $provider = null,
    ) {}

    /**
     * @param  string  $symbol  Ticker symbol (will be uppercased / trimmed).
     * @param  string|null  $exchange  Exchange short name (e.g. NYSE, NASDAQ, TSX, TSXV).
     * @param  string|null  $companyName  Optional company name from provider profile.
     * @param  string|null  $sector  Optional sector name or slug.
     * @param  string|null  $industry  Optional industry name or slug.
     * @param  string|null  $subIndustry  Optional sub-industry name or slug.
     */
    public function findOrCreate(
        string $symbol,
        ?string $exchange = null,
        ?string $companyName = null,
        ?string $sector = null,
        ?string $industry = null,
        ?string $subIndustry = null,
    ): Stock {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new InvalidArgumentException('Symbol cannot be empty.');
        }

        $existing = Stock::query()->where('symbol', $symbol)->first();
        if ($existing) {
            return $existing;
        }

        if (
            $this->provider !== null &&
            ($sector === null || $industry === null || $companyName === null || $exchange === null)
        ) {
            try {
                $profile = $this->provider->profile($symbol);
                if (is_array($profile)) {
                    $companyName ??= $profile['company_name'] ?? null;
                    $exchange ??= $profile['exchange'] ?? null;
                    $sector ??= $profile['sector'] ?? null;
                    $industry ??= $profile['industry'] ?? null;
                    $subIndustry ??= $profile['sub_industry'] ?? null;
                }
            } catch (\Throwable) {
                // Provider error/throttle — fall back gracefully.
            }
        }

        return DB::transaction(function () use ($symbol, $exchange, $companyName, $sector, $industry, $subIndustry) {
            $exchangeEnum = $this->resolveExchange($exchange);
            $currencyEnum = match ($exchangeEnum) {
                Exchange::TSX, Exchange::TSXV => Currency::CAD,
                default => Currency::USD,
            };

            $classification = $this->resolveClassification($sector, $industry, $subIndustry);

            return Stock::create([
                'symbol' => $symbol,
                'exchange' => $exchangeEnum,
                'currency' => $currencyEnum,
                'company_name' => $companyName ?: $symbol,
                'sector_id' => $classification['sector_id'],
                'industry_id' => $classification['industry_id'],
                'sub_industry_id' => $classification['sub_industry_id'],
            ]);
        });
    }

    /**
     * Resolve or create the Sector, Industry, and SubIndustry IDs.
     *
     * @return array{sector_id: int, industry_id: int, sub_industry_id: int}
     */
    public function resolveClassification(?string $sectorName = null, ?string $industryName = null, ?string $subIndustryName = null): array
    {
        $sector = $this->resolveSector($sectorName);
        $industry = $this->resolveIndustry($sector, $industryName);
        $subIndustry = $this->resolveSubIndustry($industry, $subIndustryName);

        return [
            'sector_id' => $sector->id,
            'industry_id' => $industry->id,
            'sub_industry_id' => $subIndustry->id,
        ];
    }

    /**
     * Re-resolve taxonomy for an existing Stock model and persist changes.
     */
    public function refreshClassification(
        Stock $stock,
        ?string $sector = null,
        ?string $industry = null,
        ?string $subIndustry = null,
    ): Stock {
        if (
            $this->provider !== null &&
            ($sector === null || $industry === null)
        ) {
            try {
                $profile = $this->provider->profile($stock->symbol);
                if (is_array($profile)) {
                    $sector ??= $profile['sector'] ?? null;
                    $industry ??= $profile['industry'] ?? null;
                    $subIndustry ??= $profile['sub_industry'] ?? null;
                }
            } catch (\Throwable) {
                // Ignore provider failures
            }
        }

        $classification = $this->resolveClassification($sector, $industry, $subIndustry);

        $stock->update([
            'sector_id' => $classification['sector_id'],
            'industry_id' => $classification['industry_id'],
            'sub_industry_id' => $classification['sub_industry_id'],
        ]);

        return $stock->fresh();
    }

    public function resolveSector(?string $sectorName): Sector
    {
        $name = trim((string) $sectorName);
        if ($name === '') {
            return Sector::firstOrCreate(
                ['slug' => 'unknown'],
                ['name' => 'Unknown', 'sort_order' => 9999],
            );
        }

        $aliases = [
            'technology' => 'technology',
            'information technology' => 'technology',
            'tech' => 'technology',
            'it' => 'technology',

            'industrials' => 'industrials',
            'industrial' => 'industrials',
            'industrial goods' => 'industrials',
            'capital goods' => 'industrials',

            'energy' => 'energy',
            'oil & gas' => 'energy',
            'oil and gas' => 'energy',

            'materials' => 'materials',
            'basic materials' => 'materials',

            'healthcare' => 'healthcare',
            'health care' => 'healthcare',

            'financials' => 'financials',
            'financial' => 'financials',
            'financial services' => 'financials',
            'banking' => 'financials',

            'consumer' => 'consumer',
            'consumer cyclical' => 'consumer',
            'consumer defensive' => 'consumer',
            'consumer discretionary' => 'consumer',
            'consumer staples' => 'consumer',
            'consumer goods' => 'consumer',
            'consumer services' => 'consumer',

            'telecommunications' => 'telecommunications',
            'telecommunication' => 'telecommunications',
            'telecom' => 'telecommunications',
            'telecommunication services' => 'telecommunications',
            'communication services' => 'telecommunications',
            'communication' => 'telecommunications',
            'communications' => 'telecommunications',

            'utilities' => 'utilities',
            'utility' => 'utilities',

            'real estate' => 'real-estate',
            'realestate' => 'real-estate',
            'real-estate' => 'real-estate',
        ];

        $lower = strtolower($name);
        $slug = $aliases[$lower] ?? Str::slug($name);

        $sector = Sector::query()->where('slug', $slug)->first()
            ?? Sector::query()->whereRaw('LOWER(name) = ?', [$lower])->first();

        if ($sector) {
            return $sector;
        }

        return Sector::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'sort_order' => 999],
        );
    }

    public function resolveIndustry(Sector $sector, ?string $industryName): Industry
    {
        $name = trim((string) $industryName);
        if ($name === '') {
            if ($sector->slug === 'unknown') {
                return Industry::firstOrCreate(
                    ['slug' => 'unknown-industry-'.$sector->id],
                    [
                        'sector_id' => $sector->id,
                        'name' => 'Unknown',
                        'sort_order' => 9999,
                    ],
                );
            }

            $first = $sector->industries()->first();
            if ($first) {
                return $first;
            }

            return Industry::firstOrCreate(
                ['slug' => 'general-'.$sector->id],
                [
                    'sector_id' => $sector->id,
                    'name' => 'General',
                    'sort_order' => 0,
                ],
            );
        }

        $lower = strtolower($name);
        $slug = Str::slug($name);

        // 1. Direct match within this sector
        $industry = Industry::query()
            ->where('sector_id', $sector->id)
            ->where(function ($q) use ($lower, $slug) {
                $q->whereRaw('LOWER(name) = ?', [$lower])
                    ->orWhere('slug', $slug);
            })
            ->first();

        if ($industry) {
            return $industry;
        }

        // 2. Prefix or substring matching against existing industries in this sector
        $candidates = $sector->industries()->get();
        foreach ($candidates as $candidate) {
            $candidateLower = strtolower($candidate->name);
            if (
                str_starts_with($lower, $candidateLower) ||
                str_contains($lower, $candidateLower) ||
                str_starts_with($candidateLower, $lower)
            ) {
                return $candidate;
            }
        }

        // 3. Create new Industry under this sector with unique slug
        if (Industry::query()->where('slug', $slug)->exists()) {
            $slug = Str::slug($name.'-'.$sector->id);
        }

        return Industry::firstOrCreate(
            ['slug' => $slug],
            [
                'sector_id' => $sector->id,
                'name' => $name,
                'sort_order' => 999,
            ],
        );
    }

    public function resolveSubIndustry(Industry $industry, ?string $subIndustryName): SubIndustry
    {
        $name = trim((string) $subIndustryName);
        if ($name === '') {
            $first = $industry->subIndustries()->first();
            if ($first) {
                return $first;
            }

            $slug = Str::slug($industry->name.'-sub-'.$industry->id);
            if (SubIndustry::query()->where('slug', $slug)->exists()) {
                $slug = Str::slug($industry->name.'-sub-'.uniqid());
            }

            return SubIndustry::firstOrCreate(
                ['slug' => $slug],
                [
                    'industry_id' => $industry->id,
                    'name' => $industry->name,
                    'sort_order' => 0,
                ],
            );
        }

        $lower = strtolower($name);
        $slug = Str::slug($name);

        $sub = SubIndustry::query()
            ->where('industry_id', $industry->id)
            ->where(function ($q) use ($lower, $slug) {
                $q->whereRaw('LOWER(name) = ?', [$lower])
                    ->orWhere('slug', $slug);
            })
            ->first();

        if ($sub) {
            return $sub;
        }

        $candidates = $industry->subIndustries()->get();
        foreach ($candidates as $candidate) {
            $candidateLower = strtolower($candidate->name);
            if (
                str_starts_with($lower, $candidateLower) ||
                str_contains($lower, $candidateLower) ||
                str_starts_with($candidateLower, $lower)
            ) {
                return $candidate;
            }
        }

        if (SubIndustry::query()->where('slug', $slug)->exists()) {
            $slug = Str::slug($name.'-'.$industry->id);
        }

        return SubIndustry::firstOrCreate(
            ['slug' => $slug],
            [
                'industry_id' => $industry->id,
                'name' => $name,
                'sort_order' => 999,
            ],
        );
    }

    protected function resolveExchange(?string $exchange): Exchange
    {
        if ($exchange !== null && $exchange !== '') {
            $enum = Exchange::tryFrom(strtoupper(trim($exchange)));
            if ($enum !== null) {
                return $enum;
            }
        }

        return Exchange::NYSE;
    }
}
