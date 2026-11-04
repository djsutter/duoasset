<?php

namespace App\Services\Stocks;

/**
 * Result of equity security validation.
 *
 * Note on classification semantics:
 * STATUS_CONFIRMED_EQUITY ('confirmed_common_equity') indicates that the instrument
 * has affirmative evidence of common equity (or eligible American Depositary Receipts / Shares)
 * based on available upstream provider metadata. It does not represent definitive legal verification
 * of common-share corporate status. ADRs remain fully eligible for scanning.
 */
class EquityValidationResult
{
    public const TYPE_COMMON_EQUITY = 'common_equity';
    public const TYPE_REDEEMED_BOND = 'redeemed_bond';
    public const TYPE_BOND = 'bond';
    public const TYPE_PREFERRED_STOCK = 'preferred_stock';
    public const TYPE_WARRANT = 'warrant';
    public const TYPE_RIGHT = 'right';
    public const TYPE_UNIT = 'unit';
    public const TYPE_FUND_OR_ETF = 'fund_or_etf';
    public const TYPE_INACTIVE_SECURITY = 'inactive_security';
    public const TYPE_OTHER_NON_EQUITY = 'other_non_equity';
    public const TYPE_INSUFFICIENT_METADATA = 'insufficient_metadata';
    public const TYPE_UNKNOWN = 'unknown';

    public const STATUS_CONFIRMED_EQUITY = 'confirmed_common_equity';
    public const STATUS_CONFIRMED_NON_EQUITY = 'confirmed_non_equity';
    public const STATUS_INSUFFICIENT_METADATA = 'insufficient_metadata';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public bool $isValid,
        public string $instrumentType,
        public ?string $reason = null,
        public ?string $underlyingSymbol = null,
        public array $details = [],
        public string $status = self::STATUS_CONFIRMED_EQUITY,
    ) {}

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isCommonEquity(): bool
    {
        return $this->isValid
            && $this->instrumentType === self::TYPE_COMMON_EQUITY
            && $this->status === self::STATUS_CONFIRMED_EQUITY;
    }

    public function isConfirmedNonEquity(): bool
    {
        return $this->status === self::STATUS_CONFIRMED_NON_EQUITY;
    }

    public function isInsufficientMetadata(): bool
    {
        return $this->status === self::STATUS_INSUFFICIENT_METADATA
            || in_array($this->instrumentType, [self::TYPE_INSUFFICIENT_METADATA, self::TYPE_UNKNOWN], true);
    }

    public function instrumentType(): string
    {
        return $this->instrumentType;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function underlyingSymbol(): ?string
    {
        return $this->underlyingSymbol;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
