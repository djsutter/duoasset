<?php

namespace App\Types;

final class Decimal
{
    private function __construct(
        private readonly string $value
    ) {}

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->value, $other->value, 8));
    }

    public function subtract(self $other): self
    {
        return new self(bcsub($this->value, $other->value, 8));
    }

    public function multiply(self $other): self
    {
        return new self(bcmul($this->value, $other->value, 8));
    }

    public function divide(self $other): self
    {
        return new self(bcdiv($this->value, $other->value, 8));
    }

    public function isZero(): bool
    {
        return bccomp($this->value, '0', 8) === 0;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->value, $other->value, 8) === 0;
    }

    public function lessThan(self $other): bool
    {
        return bccomp($this->value, $other->value, 8) < 0;
    }

    public function lessThanOrEqualTo(self $other): bool
    {
        return bccomp($this->value, $other->value, 8) <= 0;
    }

    public function greaterThan(self $other): bool
    {
        return bccomp($this->value, $other->value, 8) > 0;
    }

    public function isPositive(): bool
    {
        return ! str_starts_with($this->value, '-') && $this->value !== '0';
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->value, '-');
    }

    public static function min(self $a, self $b): self
    {
        return $a->lessThanOrEqualTo($b) ? $a : $b;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
