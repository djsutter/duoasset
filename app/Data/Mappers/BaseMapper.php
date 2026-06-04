<?php

namespace App\Data\Mappers;

use App\Models\Platform;
use App\Models\Wallet;
use App\Services\WalletService;
use App\Types\Money;
use Carbon\CarbonImmutable;

abstract class BaseMapper
{
    protected string $platformName = '';

    protected ?Platform $platform = null;

    protected ?string $asset = null;

    protected ?Wallet $wallet = null;

    protected ?string $walletNamePrefix = null;

    public function __construct(
        protected WalletService $walletService,
        ?string $walletName = null,
        ?string $platformName = null,
        ?string $walletNamePrefix = null,
    ) {
        if ($platformName) {
            $this->platformName = $platformName;
        }

        if (! $this->platform = Platform::where('name', $this->platformName)->first()) {
            throw (new \Exception("Expecting platform '$this->platformName' to exist."));
        }

        if ($walletName && $this->wallet = $this->walletService->getOrCreateWallet($this->platform, $walletName)) {
            $this->asset = $this->wallet->currency;
        }

        $this->walletNamePrefix = $walletNamePrefix;
    }

    /**
     * Convert a raw CSV date string to a DateTimeImmutable.
     */
    protected function parseDate(string $dateString): CarbonImmutable
    {
        try {
            // Try the simple parse first
            return CarbonImmutable::parse($dateString);
        } catch (\Exception) {
            // Clean up common quirks
            $cleaned = str_ireplace(' at ', ' ', $dateString); // remove "at"

            // Convert "GMT-0500" or "GMT+0200" to "-05:00" or "+02:00"
            $cleaned = preg_replace_callback(
                '/GMT([+-])(\d{2})(\d{2})/',
                fn ($m) => sprintf('%s%s:%s', $m[1], $m[2], $m[3]),
                $cleaned
            );

            // Remove trailing timezone in parentheses, e.g. " (Eastern Standard Time)"
            $cleaned = preg_replace('/\s*\(.*\)$/', '', $cleaned);

            return CarbonImmutable::parse($cleaned);
        }
    }

    /**
     * Convert a raw decimal string or number to a Money object.
     */
    protected function toMoney(?string $value, ?string $asset = null): ?Money
    {
        $asset ??= $this->asset;

        // Trim ETH to 8 decimals on import because the source numbers don't quite add up otherwise
        if ($asset == 'ETH') {
            $value = sprintf('%.8f', (float) $value);
        }

        return $value !== null ? Money::fromDecimal($value, $asset) : null;
    }
}
