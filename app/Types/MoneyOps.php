<?php

namespace App\Types;

use App\Support\CurrencyRegistry;
use InvalidArgumentException;

final class MoneyOps
{
    private const THOUSANDS_REGEX = '/\B(?=(\d{3})+(?!\d))/';

    /** ----- Assertions ----- */
    public static function ensureSameCurrency(Money $a, Money $b): void
    {
        if ($a->currency !== $b->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$a->currency} vs {$b->currency}"
            );
        }
    }

    /** ----- Properties ----- */
    public static function isZero(Money $money): bool
    {
        return $money->amount === '0';
    }

    public static function isPositive(Money $money): bool
    {
        return ! str_starts_with($money->amount, '-') && $money->amount !== '0';
    }

    public static function isNegative(Money $money): bool
    {
        return str_starts_with($money->amount, '-');
    }

    public static function scale(Money $money): int
    {
        return $money->scale;
    }

    // Returns -1, 0, or 1 depending on the sign:
    public static function sign(Money $money): int
    {
        return bccomp($money->amount, '0', 0);
    }

    /** ----- Arithmetic ----- */
    public static function add(Money $a, Money $b): Money
    {
        self::ensureSameCurrency($a, $b);

        return new Money(bcadd($a->amount, $b->amount, 0), $a->currency);
    }

    /**
     * @deprecated same as add() now
     */
    public static function fastAdd(Money $a, Money $b): Money
    {
        return self::add($a, $b);
    }

    public static function subtract(Money $a, Money $b): Money
    {
        self::ensureSameCurrency($a, $b);

        return new Money(bcsub($a->amount, $b->amount, 0), $a->currency);
    }

    public static function multiply(Money $money, string $multiplier): Money
    {
        return new Money(bcmul($money->amount, $multiplier, $money->scale), $money->currency);
    }

    public static function divide(Money $money, string $divisor): Money
    {
        if ($divisor === '0') {
            throw new \DivisionByZeroError('Cannot divide by zero.');
        }

        return new Money(bcdiv($money->amount, $divisor, $money->scale), $money->currency);
    }

    public static function negated(Money $money): Money
    {
        if ($money->isZero()) {
            return $money; // Avoid "-0"
        }

        return new Money(str_starts_with($money->amount, '-') ? substr($money->amount, 1) : '-'.$money->amount, $money->currency);
    }

    public static function abs(Money $money): Money
    {
        return new Money(ltrim($money->amount, '-'), $money->currency);
    }

    public static function min(Money $a, Money $b): Money
    {
        self::ensureSameCurrency($a, $b);

        return bccomp($a->amount, $b->amount, max($a->scale, $b->scale)) <= 0
            ? $a
            : $b;
    }

    /** ----- Comparison ----- */
    public static function compare(Money $a, Money $b): int
    {
        self::ensureSameCurrency($a, $b);

        return bccomp($a->amount, $b->amount, 0);
    }

    public static function equals(Money $a, Money $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function equalsWithTolerance(Money $a, Money $b, ?string $toleranceDecimal = null): bool
    {
        // Default tolerance is 1 unit in the last decimal place
        $toleranceDecimal ??= '0.'.str_repeat('0', $a->scale - 1).'1';
        $difference = self::abs(self::subtract($a, $b));

        return self::lessThanOrEqualTo($difference, Money::fromDecimal($toleranceDecimal, $a->currency));
    }

    public static function greaterThan(Money $a, Money $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    public static function greaterThanOrEqualTo(Money $a, Money $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    public static function lessThan(Money $a, Money $b): bool
    {
        return self::compare($a, $b) < 0;
    }

    public static function lessThanOrEqualTo(Money $a, Money $b): bool
    {
        return self::compare($a, $b) <= 0;
    }

    /** ----- Conversion ----- */

    /**
     * Convert a money value to a decimal string representation.
     */
    public static function toDecimal(Money $money): string
    {
        return bcdiv($money->amount, Money::pow10($money->scale), $money->scale);
    }

    /**
     * Convert a money value to a decimal string with specified scale.
     */
    public static function toRoundedDecimal(Money $money, ?int $toScale = null): string
    {
        if (is_null($toScale)) {
            $toScale = max(0, CurrencyRegistry::get($money->currency)->display_scale);
        }

        return bcdiv($money->amount, Money::pow10($money->scale), $toScale);
    }

    /** ----- Formatting ----- */
    /**
     * Format a money value as a string.
     */
    public static function format(Money $money, int $flags = 0): string
    {
        if ($flags & Money::HIDE_ZERO && self::isZero($money)) {
            return '';
        }

        $currencyDef = CurrencyRegistry::get($money->currency);
        $displayScale = max(0, (int) $currencyDef->display_scale);

        $includeSymbol = ($flags & Money::SYMBOL) === Money::SYMBOL;
        $thousandsSeparator = ($flags & Money::NO_THOUSANDS_SEP) ? '' : ',';

        // Calculate the value string (integer string representing full units)
        if ($flags & Money::ROUND_DISPLAY) {
            // Add one extra digit for rounding
            $value = bcdiv(
                $money->amount,
                Money::pow10($money->scale),
                $displayScale + 1
            );
            $value = self::bcRound($value, $displayScale);
        } else {
            $value = bcdiv(
                $money->amount,
                Money::pow10($money->scale),
                $displayScale
            );
        }

        // Handle zero-scale (integer) formatting
        if ($displayScale === 0) {
            $intStr = (string) $value;
            $sign = '';

            if (str_starts_with($intStr, '-')) {
                $sign = '-';
                $intStr = substr($intStr, 1);
            }

            if ($thousandsSeparator !== '') {
                $intStr = preg_replace(self::THOUSANDS_REGEX, $thousandsSeparator, $intStr);
            }

            $formatted = $sign.$intStr;

            return $includeSymbol ? $currencyDef->symbol.$formatted : $formatted;
        }

        // Handle fractional part formatting
        [$intPart, $fracPart] = explode('.', $value.'.'.str_repeat('0', $displayScale));
        $sign = '';
        if (str_starts_with($intPart, '-')) {
            $sign = '-';
            $intPart = substr($intPart, 1);
        }

        if ($thousandsSeparator !== '') {
            // $intPart = number_format((int) $intPart, 0, '', $thousandsSeparator);
            $intPart = preg_replace(self::THOUSANDS_REGEX, $thousandsSeparator, $intPart);
        }

        // Trim or pad the fractional part to match display scale
        $fracPart = str_pad(substr($fracPart, 0, $displayScale), $displayScale, '0', STR_PAD_RIGHT);

        $formatted = $sign.$intPart.'.'.$fracPart;

        return $includeSymbol ? $currencyDef->symbol.$formatted : $formatted;
    }

    private static function bcRound(string $value, int $scale): string
    {
        if (strpos($value, '.') === false) {
            return $value;
        }

        [$int, $frac] = explode('.', $value, 2);

        // Pad to ensure we can inspect the rounding digit
        $frac = str_pad($frac, $scale + 1, '0');

        $roundDigit = (int) $frac[$scale];
        $frac = substr($frac, 0, $scale);

        if ($roundDigit >= 5) {
            return bcadd(
                $int.'.'.$frac,
                '0.'.str_repeat('0', $scale - 1).'1',
                $scale
            );
        }

        return $int.'.'.$frac;
    }

    /**
     * Absolute value for Money using BC math.
     */
    private static function bcabs(string $value, int $scale = 0): string
    {
        return (bccomp($value, '0', $scale) < 0) ? bcmul($value, '-1', $scale) : $value;
    }
}
