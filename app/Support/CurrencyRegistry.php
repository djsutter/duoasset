<?php

namespace App\Support;

use App\Enums\CurrencyType;
use App\Models\Currency;
use Illuminate\Support\Facades\Cache;

class CurrencyRegistry
{
    public const SMALL_SCALE = 6;

    public static function get(string $currency): ?Currency
    {
        return Cache::rememberForever("currency.{$currency}", function () use ($currency) {
            return Currency::where('currency_code', $currency)->first();
        });
    }

    public static function getScale(string $currency): int
    {
        $currency = strtoupper($currency);

        if ($currencyDef = self::get($currency)) {
            return $currencyDef->scale;
        }

        throw new \InvalidArgumentException("Unknown or unsupported currency: {$currency}");
    }

    public static function getDisplayScale(string $currency): int
    {
        $currency = strtoupper($currency);

        if ($currencyDef = self::get($currency)) {
            return $currencyDef->display_scale;
        }

        throw new \InvalidArgumentException("Unknown or unsupported currency: {$currency}");
    }

    public static function isSmallScale(string $currency): bool
    {
        return self::getScale($currency) <= self::SMALL_SCALE;
    }

    public static function typeFor(string $currency): CurrencyType
    {
        $currency = strtoupper($currency);

        if ($currencyDef = CurrencyRegistry::get($currency)) {
            return $currencyDef->type;
        }

        throw new \InvalidArgumentException("Unknown or unsupported currency: {$currency}");
    }
}
