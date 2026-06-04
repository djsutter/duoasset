<?php

namespace App\Types;

use InvalidArgumentException;

class MoneySum
{
    public const HIDE_ZERO = 0b0001;

    protected Money $total;

    protected string $currency;

    protected int $count = 0;

    public function __construct(string $currency)
    {
        $this->total = Money::zero($currency);
        $this->currency = $currency;
    }

    public function clone(): self
    {
        $copy = new self($this->currency);
        $copy->total = $this->total;
        $copy->count = $this->count;

        return $copy;
    }

    public function add(Money $amount): void
    {
        $this->total = $this->total->add($amount);
        $this->count++;
    }

    /**
     * @deprecated use subtract() instead
     */
    public function sub(Money $amount): void
    {
        $this->total = $this->total->subtract($amount);
        $this->count++;
    }

    public function subtract(Money $amount): void
    {
        $this->total = $this->total->subtract($amount);
        $this->count++;
    }

    public function amount(): Money
    {
        return $this->total;
    }

    public function decimal(): string
    {
        return $this->total->toDecimal();
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function toMoney(): Money
    {
        return $this->total;
    }

    /**
     * @param  $zero  Use null to return null instead of zero.
     */
    public function total(?bool $zero = true): ?Money
    {
        if ($zero === null && $this->total->isZero()) {
            return null;
        }

        return $this->total;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function diff(MoneySum $other): Money
    {
        $this->assertSameCurrency($other);

        return $this->total->subtract($other->total());
    }

    /**
     * Return the total as a formatted string.
     */
    public function format(int $flags = 0): string
    {
        if ($flags & self::HIDE_ZERO && $this->total->isZero()) {
            return '';
        }

        return $this->total->format();
    }

    public function isZero(): bool
    {
        return $this->total->isZero();
    }

    public function merge(self $other): void
    {
        $this->assertSameCurrency($other);
        $this->total = $this->total->add($other->total());
        $this->count += $other->count();
    }

    protected function assertSameCurrency(MoneySum $other): void
    {
        if ($other->currency() !== $this->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency()}");
        }
    }
}
