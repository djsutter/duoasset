<?php

namespace App\Casts;

use App\Types\FiatMoney;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

class FiatMoneyCast implements CastsAttributes
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
            // Currency missing → cannot create valid FiatMoney
            return null;
        }

        return FiatMoney::fromMinorUnits($value, $currency);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! $value instanceof FiatMoney) {
            throw new InvalidArgumentException("Expected instance of FiatMoney for attribute [$key].");
        }

        $expectedCurrency = $this->resolveCurrency($model, $attributes, $key);

        if ($expectedCurrency && strcasecmp($value->currency, $expectedCurrency) !== 0) {
            throw new InvalidArgumentException(
                "Currency mismatch for [$key]: got [{$value->currency}], expected [$expectedCurrency]."
            );
        }

        if ($this->currencyField) {
            return [
                $key => $value->minor,
                $this->currencyField => $value->currency,
            ];
        }

        return [$key => $value->minor];
    }

    protected function resolveCurrency($model, array $attributes, string $key): ?string
    {
        if ($this->currencyField) {
            $value = $model->{$this->currencyField}
                ?? $attributes[$this->currencyField]
                ?? null;

            if ($value instanceof \BackedEnum) {
                return (string) $value->value;
            }

            return $value !== null ? (string) $value : null;
        }

        return getReportingCurrency();
    }
}
