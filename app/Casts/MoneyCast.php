<?php

namespace App\Casts;

use App\Types\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

class MoneyCast implements CastsAttributes
{
    protected ?string $currencyField;

    public function __construct(?string $currencyField = null)
    {
        // null means "use reporting currency"
        $this->currencyField = $currencyField ?: null;
    }

    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || ! isset($attributes[$key])) {
            return null;
        }

        $currency = $this->resolveCurrency($model, $attributes, $key);

        if (! $currency) {
            // Currency missing → cannot create valid Money
            return null;
        }

        return Money::fromDecimal($value, $currency);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException("Expected instance of Money for attribute [$key].");
        }

        $expectedCurrency = $this->resolveCurrency($model, $attributes, $key);

        if ($expectedCurrency && strcasecmp($value->currency, $expectedCurrency) !== 0) {
            throw new InvalidArgumentException(
                "Currency mismatch for [$key]: got [{$value->currency}], expected [$expectedCurrency]."
            );
        }

        $decimalString = $value->toDecimal();

        if ($this->currencyField) {
            return [
                $key => $decimalString,
                $this->currencyField => $value->currency,
            ];
        }

        return [$key => $decimalString];
    }

    protected function resolveCurrency($model, array $attributes, string $key): ?string
    {
        if ($this->currencyField) {
            return $model->{$this->currencyField}
                ?? $attributes[$this->currencyField]
                ?? null;
        }

        return getReportingCurrency();
    }
}
