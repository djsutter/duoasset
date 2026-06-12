<?php

namespace App\Types;

use InvalidArgumentException;

class FiatMoneySum
{
    public const HIDE_ZERO = 0b0001;

    protected FiatMoney $total;

    protected string $currency;

    protected int $count = 0;

    public function __construct(string $currency)
    {
        $this->total = FiatMoney::zero($currency);
        $this->currency = $currency;
    }

    public function clone(): self
    {
        $copy = new self($this->currency);
        $copy->total = $this->total;
        $copy->count = $this->count;

        return $copy;
    }

    public function add(FiatMoney $amount): void
    {
        $this->total = $this->total->add($amount);
        $this->count++;
    }

    public function subtract(FiatMoney $amount): void
    {
        $this->total = $this->total->subtract($amount);
        $this->count++;
    }

    public function amount(): FiatMoney
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

    public function toMoney(): FiatMoney
    {
        return $this->total;
    }

    /**
     * @param  $zero  Use null to return null instead of zero.
     */
    public function total(?bool $zero = true): ?FiatMoney
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

    public function diff(FiatMoneySum $other): FiatMoney
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

    protected function assertSameCurrency(FiatMoneySum $other): void
    {
        if ($other->currency() !== $this->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency()}");
        }
    }
}
