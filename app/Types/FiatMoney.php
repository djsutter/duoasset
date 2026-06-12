<?php

namespace App\Types;

use App\Support\CurrencyRegistry;

final class FiatMoney
{
    public const HIDE_ZERO = 1 << 0;

    public const SYMBOL = 1 << 1;

    public const NO_THOUSANDS_SEP = 1 << 2;

    private const SCALE = 4;

    private const MULTIPLIER = 10000; // 10^4

    public function __construct(
        public readonly int $minor,
        public readonly string $currency
    ) {}

    /** ----- Creation ----- */
    public static function fromDecimal(string $decimal, string $currency): self
    {
        $decimal = str_replace(',', '', trim($decimal));

        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');

        if (! str_contains($decimal, '.')) {
            $decimal .= '.';
        }

        [$int, $frac] = explode('.', $decimal, 2);

        $frac = str_pad(substr($frac, 0, self::SCALE), self::SCALE, '0');

        $minor = ((int) $int * self::MULTIPLIER) + (int) $frac;

        if ($negative) {
            $minor *= -1;
        }

        return new self($minor, $currency);
    }

    public static function fromMinorUnits(int $minor, string $currency): FiatMoney
    {
        return new self($minor, $currency);
    }

    public static function fromValidatedDecimal(?string $decimal, string $currency): ?FiatMoney
    {
        if (is_null($decimal)) {
            return null;
        }

        return self::fromDecimal($decimal, $currency);
    }

    public static function zero(string $currency): FiatMoney
    {
        return new FiatMoney(0, $currency);
    }

    /** ----- Assertions ----- */
    public function ensureSameCurrency(FiatMoney $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}"
            );
        }
    }

    /** ----- Helpers ----- */
    public function round(int $targetScale): self
    {
        if ($targetScale < 0 || $targetScale > self::SCALE) {
            throw new \InvalidArgumentException(
                'Target scale must be between 0 and '.self::SCALE
            );
        }

        // No rounding needed
        if ($targetScale === self::SCALE) {
            return $this;
        }

        $scaleDiff = self::SCALE - $targetScale;

        $factor = 10 ** $scaleDiff;

        $value = $this->minor;

        // Determine remainder relative to target precision
        $remainder = $value % $factor;
        $base = intdiv($value, $factor);

        // Half-up rounding threshold
        $half = intdiv($factor, 2);

        if (abs($remainder) >= $half) {
            $base += $value >= 0 ? 1 : -1;
        }

        $rounded = $base * $factor;

        return new self($rounded, $this->currency);
    }

    private static function parseDecimal(string $decimal): array
    {
        $decimal = str_replace(',', '', trim($decimal));

        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');

        if (! str_contains($decimal, '.')) {
            return [(int) $decimal, 0, $negative];
        }

        [$int, $frac] = explode('.', $decimal, 2);

        $frac = rtrim($frac, '0');

        $scale = strlen($frac);

        $combined = ((int) $int * (10 ** $scale)) + (int) $frac;

        return [$combined, $scale, $negative];
    }

    /** ----- Properties ----- */
    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isZeroOrNegative(): bool
    {
        return $this->minor <= 0;
    }

    public function isZeroOrPositive(): bool
    {
        return $this->minor >= 0;
    }

    // Returns -1, 0, or 1 depending on the sign:
    public function sign(): int
    {
        return $this->minor <=> 0;
    }

    /** ----- Comparison ----- */
    public function compare(FiatMoney $other): int
    {
        $this->ensureSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function equals(FiatMoney $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->minor === $other->minor;
    }

    public function greaterThan(FiatMoney $other): bool
    {
        return $this->minor > $other->minor;
    }

    public function greaterThanOrEqualTo(FiatMoney $other): bool
    {
        return $this->minor >= $other->minor;
    }

    public function lessThan(FiatMoney $other): bool
    {
        return $this->minor < $other->minor;
    }

    public function lessThanOrEqualTo(FiatMoney $other): bool
    {
        return $this->minor <= $other->minor;
    }

    /** ----- Arithmetic ----- */
    public function add(FiatMoney $other): self
    {
        $this->ensureSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(FiatMoney $other): self
    {
        $this->ensureSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Multiply by an integer without the need for rounding.
     */
    public function multiplyInt(int $factor): self
    {
        return new self($this->minor * $factor, $this->currency);
    }

    public function multiply(string $factor, int $roundScale = self::SCALE): self
    {
        if ($roundScale < 0 || $roundScale > self::SCALE) {
            throw new \InvalidArgumentException('Invalid round scale.');
        }

        [$factorInt, $factorScale, $negative] = self::parseDecimal($factor);

        if ($negative) {
            $factorInt *= -1;
        }

        // Multiply raw integers
        $raw = $this->minor * $factorInt;

        // After multiplication:
        // money scale = 4
        // factor scale = factorScale
        // total scale = 4 + factorScale

        $targetScale = $roundScale;
        $currentScale = self::SCALE + $factorScale;

        $scaleDiff = $currentScale - $targetScale;

        if ($scaleDiff > 0) {
            $divisor = 10 ** $scaleDiff;

            $remainder = $raw % $divisor;
            $base = intdiv($raw, $divisor);

            $half = intdiv($divisor, 2);

            if (abs($remainder) >= $half) {
                $base += $raw >= 0 ? 1 : -1;
            }

            $raw = $base;
        }

        return new self($raw, $this->currency);
    }

    /**
     * Divide by an integer without the need for rounding.
     */
    public function divideInt(int $divisor): self
    {
        if ($divisor === 0) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        return new self($this->minor / $divisor, $this->currency);
    }

    public function divide(string $divisor, int $roundScale = self::SCALE): self
    {
        if ($roundScale < 0 || $roundScale > self::SCALE) {
            throw new \InvalidArgumentException('Invalid round scale.');
        }

        [$divInt, $divScale, $negative] = self::parseDecimal($divisor);

        if ($divInt === 0) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        if ($negative) {
            $divInt *= -1;
        }

        // We want final scale = roundScale
        // money scale = 4
        // divisor scale = divScale
        //
        // To preserve precision:
        // upscale numerator before dividing

        $scaleAdjustment = $roundScale + $divScale - self::SCALE;

        if ($scaleAdjustment > 0) {
            $numerator = $this->minor * (10 ** $scaleAdjustment);
        } else {
            $numerator = intdiv($this->minor, 10 ** abs($scaleAdjustment));
        }

        $raw = intdiv($numerator, $divInt);
        $remainder = $numerator % $divInt;

        $half = intdiv(abs($divInt), 2);

        if (abs($remainder) >= $half) {
            $raw += ($numerator >= 0) === ($divInt >= 0) ? 1 : -1;
        }

        return new self($raw, $this->currency);
    }

    public function negate(): self
    {
        return new self(0 - $this->minor, $this->currency);
    }

    public function negated(): self
    {
        return new self(0 - $this->minor, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->minor), $this->currency);
    }

    public static function max(FiatMoney ...$values): self
    {
        if (empty($values)) {
            throw new \InvalidArgumentException('No values provided.');
        }

        $currency = $values[0]->currency;

        foreach ($values as $v) {
            if ($v->currency !== $currency) {
                throw new \InvalidArgumentException('Currency mismatch.');
            }
        }

        $minMinor = max(array_map(fn ($v) => $v->minor, $values));

        return new self($minMinor, $currency);
    }

    public static function min(FiatMoney ...$values): self
    {
        if (empty($values)) {
            throw new \InvalidArgumentException('No values provided.');
        }

        $currency = $values[0]->currency;

        foreach ($values as $v) {
            if ($v->currency !== $currency) {
                throw new \InvalidArgumentException('Currency mismatch.');
            }
        }

        $minMinor = min(array_map(fn ($v) => $v->minor, $values));

        return new self($minMinor, $currency);
    }

    /** ----- Conversion ----- */

    /**
     * Convert a fiat money value to a decimal string representation.
     */
    public function toDecimal(): string
    {
        $rounded = $this->round(2);

        $displayUnits = intdiv($rounded->minor, 100);

        return number_format($displayUnits / 100, 2, '.', '');
    }

    public function toMinorUnits(): int
    {
        return $this->minor;
    }

    /**
     * Convert a money value to a decimal string with specified scale.
     */
    public function toRoundedDecimal(int $scale = 2): string
    {
        return $this->round($scale)->toDecimal();
    }

    /** ----- Formatting ----- */

    /**
     * Format a fiat money value as a string.
     */
    public function format(int $flags = 0): string
    {
        if (($flags & self::HIDE_ZERO) && $this->minor === 0) {
            return '';
        }

        $includeSymbol = ($flags & self::SYMBOL) === self::SYMBOL;
        $useThousands = ! ($flags & self::NO_THOUSANDS_SEP);
        $displayScale = 2;
        $scaleDiff = self::SCALE - $displayScale;
        $divisor = 10 ** $scaleDiff;

        // Convert to 2-decimal scaled integer
        $rounded = $this->round(2);
        $displayUnits = intdiv($rounded->minor, $divisor);

        // Convert to real decimal number safely
        $numeric = $displayUnits / 100;

        $formatted = number_format(
            $numeric,
            2,
            '.',
            $useThousands ? ',' : ''
        );

        if ($includeSymbol) {
            $currencyDef = CurrencyRegistry::get($this->currency);

            return $currencyDef->symbol.$formatted;
        }

        return $formatted;
    }
}
