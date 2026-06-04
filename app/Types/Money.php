<?php

namespace App\Types;

use App\Support\CurrencyRegistry;

final class Money
{
    public const HIDE_ZERO = 1 << 0;

    public const SYMBOL = 1 << 1;

    public const NO_THOUSANDS_SEP = 1 << 2;

    // rounding-related flags
    public const ROUND_DISPLAY = 1 << 8;

    public readonly string $amount;

    public readonly string $currency;

    public readonly int $scale;

    private static array $pow10 = [];

    public function __construct(string $amount, string $currency, ?int $scale = null)
    {
        $this->amount = $this->normalizeAmount($amount);
        $this->currency = $currency;
        $this->scale = $scale ?? CurrencyRegistry::getScale($currency);
    }

    private function normalizeAmount(string $amount): string
    {
        $negative = false;

        if (str_starts_with($amount, '-')) {
            $negative = true;
            $amount = substr($amount, 1);
        }

        $amount = ltrim($amount, '0');

        if ($amount === '') {
            return '0'; // zero
        }

        return $negative ? "-$amount" : $amount;
    }

    /** ----- Creation ----- */
    public static function create(string $amount, string $currency): Money
    {
        return new self($amount, $currency);
    }

    public static function fromDecimal(string $decimal, string $currency): Money
    {
        $scale = CurrencyRegistry::getScale($currency);

        if (str_contains($decimal, 'e') || str_contains($decimal, 'E')) {
            $decimal = sprintf('%.'.$scale.'f', (float) $decimal);
        }

        $factor = self::pow10($scale);

        return new Money(bcmul($decimal, $factor, 0), $currency);
    }

    public static function fromMinorUnits(string $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public function minorUnits(): string
    {
        return $this->amount;
    }

    public static function fromValidatedDecimal(?string $decimal, string $currency): ?Money
    {
        if (is_null($decimal)) {
            return null;
        }

        return self::fromDecimal(number_unformat($decimal), $currency);
    }

    public static function zero(string $currency): Money
    {
        return new Money('0', $currency);
    }

    /** ----- Helpers ----- */
    public function isSmallScale(): bool
    {
        return CurrencyRegistry::isSmallScale($this->currency);
    }

    public static function pow10(int $scale): string
    {
        return self::$pow10[$scale] ??= bcpow('10', (string) $scale);
    }

    public function round(int $targetScale): self
    {
        if ($targetScale < 0) {
            throw new \InvalidArgumentException('Target scale must be >= 0');
        }

        if ($this->scale <= $targetScale) {
            return $this;
        }

        $delta = $this->scale - $targetScale;
        $factor = self::pow10($delta);

        $base = bcdiv($this->amount, $factor, 0);
        $remainder = bcmod($this->amount, $factor);
        $half = bcdiv($factor, '2', 0);

        // Use manual absolute check
        $isNegative = $this->amount[0] === '-';
        $absRemainder = $isNegative ? bcsub('0', $remainder) : $remainder;

        if (bccomp($absRemainder, $half) >= 0) {
            $base = $isNegative ? bcsub($base, '1', 0) : bcadd($base, '1', 0);
        }

        return new self(
            amount: $base,
            currency: $this->currency,
            scale: $targetScale
        );
    }

    /** ----- Properties ----- */
    public function isNegative(): bool
    {
        return MoneyOps::isNegative($this);
    }

    public function isPositive(): bool
    {
        return MoneyOps::isPositive($this);
    }

    public function isZero(): bool
    {
        return MoneyOps::isZero($this);
    }

    public function isZeroOrNegative(): bool
    {
        return $this->isZero() || $this->isNegative();
    }

    public function isZeroOrPositive(): bool
    {
        return $this->isZero() || $this->isPositive();
    }

    // Returns -1, 0, or 1 depending on the sign:
    public function sign(): int
    {
        return MoneyOps::sign($this);
    }

    /** ----- Comparison ----- */
    public function compare(Money $other): int
    {
        return MoneyOps::compare($this, $other);
    }

    public function equals(Money $other): bool
    {
        return MoneyOps::equals($this, $other);
    }

    /**
     * Compare this Money to another with a tolerance.
     *
     * @param  Money  $other  The other Money object.
     * @param  string|float|null  $tolerance  Optional tolerance. Defaults to 10^-scale.
     * @return bool True if amounts are equal within the tolerance.
     */
    public function equalsWithTolerance(Money $other, ?string $tolerance = null): bool
    {
        return MoneyOps::equalsWithTolerance($this, $other, $tolerance);
    }

    public function greaterThan(Money $other): bool
    {
        return MoneyOps::greaterThan($this, $other);
    }

    public function greaterThanOrEqualTo(Money $other): bool
    {
        return MoneyOps::greaterThanOrEqualTo($this, $other);
    }

    public function lessThan(Money $other): bool
    {
        return MoneyOps::lessThan($this, $other);
    }

    public function lessThanOrEqualTo(Money $other): bool
    {
        return MoneyOps::lessThanOrEqualTo($this, $other);
    }

    /** ----- Arithmetic ----- */
    /**
     * @deprecated same as add() now
     */
    public function fastAdd(Money $other): Money
    {
        return MoneyOps::add($this, $other);
    }

    public function add(Money $other): self
    {
        return MoneyOps::add($this, $other);
    }

    public function subtract(Money $other): self
    {
        return MoneyOps::subtract($this, $other);
    }

    public function multiply(Money|string $multiplier): self
    {
        if ($multiplier instanceof Money) {
            $multiplier = $multiplier->toDecimal();
        }

        return MoneyOps::multiply($this, $multiplier);
    }

    public function divide(Money|string $divisor): self
    {
        if ($divisor instanceof Money) {
            $divisor = $divisor->toDecimal();
        }

        return MoneyOps::divide($this, $divisor);
    }

    public function multiplyByQuantity(AssetQuantity $quantity): self
    {
        return MoneyOps::multiply($this, $quantity->amount);
    }

    public function divideByQuantity(AssetQuantity $quantity): self
    {
        if ($quantity->amount === '0') {
            throw new \DivisionByZeroError('Cannot divide by zero quantity.');
        }

        return MoneyOps::divide($this, $quantity->amount);
    }

    public function negate(): self
    {
        return MoneyOps::negated($this);
    }

    public function negated(): self
    {
        return MoneyOps::negated($this);
    }

    public function abs(): self
    {
        return MoneyOps::abs($this);
    }

    public static function min(Money $a, Money $b): self
    {
        return MoneyOps::min($a, $b);
    }

    /** ----- Conversion ----- */
    /**
     * Convert a money value to a decimal string representation.
     */
    public function toDecimal(): string
    {
        return MoneyOps::toDecimal($this);
    }

    /**
     * Convert a money value to a decimal string with specified scale.
     */
    public function toRoundedDecimal(int $scale = 2): string
    {
        return MoneyOps::toRoundedDecimal($this, $scale);
    }

    /** ----- Formatting ----- */
    /**
     * Format a money value as a string.
     */
    public function format(int $flags = 0): string
    {
        return MoneyOps::format($this, $flags);
    }

    /**
     * Apply a direction to the amount (positive, negative, or zero).
     */
    public function withDirection(int $direction): self
    {
        if (! in_array($direction, [-1, 0, 1], true)) {
            throw new \InvalidArgumentException('Direction must be -1, 0, or 1');
        }

        if ($direction === 0) {
            return self::zero($this->currency);
        }

        if ($direction === 1) {
            return $this;
        }

        return $this->negated();
    }
}
