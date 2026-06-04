<?php

namespace App\Types;

use App\Models\Asset;
use App\Support\CurrencyRegistry;

final class AssetQuantity
{
    private static array $displayScaleCache = [];

    public function __construct(
        public readonly string $amount, // decimal string
        public readonly string $asset_code
    ) {}

    public static function fromMoney(Money $money, Asset $asset): self
    {
        if ($money->currency !== $asset->asset_code) {
            throw new \LogicException('Currency / asset mismatch');
        }

        $amount = bcdiv(
            $money->amount,
            bcpow('10', (string) $money->scale),
            $money->scale
        );

        return new self($amount, $asset->asset_code);
    }

    public static function fromDecimal(string|Decimal $decimal, string $assetCode): self
    {
        if ($decimal instanceof Decimal) {
            return new self($decimal->toString(), $assetCode);
        }

        return new self($decimal, $assetCode);
    }

    public function toMoney(): Money
    {
        return Money::fromDecimal($this->amount, $this->asset_code);
    }

    public function toDecimal(): string
    {
        return $this->amount;
    }

    public function toDecimalObject(): Decimal
    {
        return Decimal::fromString($this->amount);
    }

    public static function zero(Asset|string $asset): self
    {
        $asset_code = $asset instanceof Asset ? $asset->asset_code : $asset;

        return new self('0', $asset_code);
    }

    public function isZero(): bool
    {
        return $this->amount == '0';
    }

    public function isPositive(): bool
    {
        return ! str_starts_with($this->amount, '-') && $this->amount !== '0';
    }

    public function isNegative(): bool
    {
        return str_starts_with($this->amount, '-');
    }

    public function compare(AssetQuantity $other): int
    {
        $this->assertSameAsset($other);

        return bccomp($this->amount, $other->amount, 18);
    }

    public function equals(AssetQuantity $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function greaterThan(AssetQuantity $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function greaterThanOrEqualTo(AssetQuantity $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function lessThan(AssetQuantity $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function lessThanOrEqualTo(AssetQuantity $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public static function min(array $quantities): AssetQuantity
    {
        $min = reset($quantities);
        foreach ($quantities as $q) {
            if ($q->lessThan($min)) {
                $min = $q;
            }
        }

        return $min;
    }

    public function abs(): self
    {
        return new self(ltrim($this->amount, '-'), $this->asset_code);
    }

    public function add(self $other): self
    {
        $this->assertSameAsset($other);

        return new self(
            bcadd($this->amount, $other->amount, 18),
            $this->asset_code
        );
    }

    public function subtract(self $other): self
    {
        $this->assertSameAsset($other);

        return new self(
            bcsub($this->amount, $other->amount, 18),
            $this->asset_code,
        );
    }

    public function multiply(self $other): self
    {
        $this->assertSameAsset($other);

        return new self(
            bcmul($this->amount, $other->amount, 18),
            $this->asset_code
        );
    }

    public function divide(self $other): self
    {
        $this->assertSameAsset($other);

        return new self(
            bcdiv($this->amount, $other->amount, 18),
            $this->asset_code
        );
    }

    public function negated(): self
    {
        if ($this->isZero()) {
            return $this;  // Avoid "-0"
        }

        return new self(
            str_starts_with($this->amount, '-') ? substr($this->amount, 1) : '-'.$this->amount,
            $this->asset_code,
        );
    }

    // Removed at the suggestion of ChatGPT. It is correct but conceptually dangerous.
    // abs() should not be used to "fix" inventory logic errors
    // public function abs(): self
    // {
    //     return new self(
    //         amount: ltrim($this->amount, '-'), // remove negative sign if present
    //         asset_code: $this->asset_code
    //     );
    // }

    private function assertSameAsset(self $other): void
    {
        if ($this->asset_code !== $other->asset_code) {
            throw new \LogicException('Asset mismatch ('.$this->asset_code.' vs '.$other->asset_code.')');
        }
    }

    /**
     * Apply a direction to the quantity (positive, negative, or zero).
     */
    public function withDirection(int $direction): self
    {
        if (! in_array($direction, [-1, 0, 1], true)) {
            throw new \InvalidArgumentException('Direction must be -1, 0, or 1');
        }

        if ($direction === 0) {
            return self::zero($this->asset_code);
        }

        if ($direction === 1) {
            return $this;
        }

        return self::zero($this->asset_code)->subtract($this);
    }

    private function displayScale(): int
    {
        if (empty(self::$displayScaleCache[$this->asset_code])) {
            self::$displayScaleCache[$this->asset_code] = CurrencyRegistry::get($this->asset_code)->display_scale ?? 8;
        }

        return self::$displayScaleCache[$this->asset_code];
    }

    public function format(?int $scale = null): string
    {
        $displayScale = $scale ?? $this->displayScale();

        return number_format($this->toDecimal(), $displayScale);
    }
}
