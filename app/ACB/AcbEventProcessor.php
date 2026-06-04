<?php

namespace App\ACB;

use App\Data\ACB\AcbEventData;
use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use App\Models\Asset;
use App\Support\CurrencyRegistry;
use App\Types\AssetQuantity;
use App\Types\Money;

class AcbEventProcessor
{
    /**
     * Process a single ACB event and persist its effects.
     *
     * @param  string|null  $forAssetCode  Optional filter to restrict processing to a specific asset
     */
    public static function process(AcbEventData $event, ?string $forAssetCode = null): void
    {
        if ($forAssetCode) {
            if (! $assetCode = self::getAssetCode($event)) {
                return;
            }
            if ($assetCode != $forAssetCode) {
                return;
            }
        }

        $asset = self::resolveAsset($event);

        if (! $asset || $asset->asset_code == getReportingCurrency()) {
            return;
        }

        match ($event->event_type) {
            AcbEventType::Acquisition => self::processAcquisition($asset, $event),
            AcbEventType::Disposal => self::processDisposal($asset, $event),
            AcbEventType::TransferFee => self::processTransferFee($asset, $event),
            default => null,
        };
    }

    /* ============================================================
     *  RESOLUTION HELPERS
     * ============================================================ */

    /**
     * Resolve the asset code from an event's foreign amount or fee.
     */
    private static function getAssetCode(AcbEventData $event): ?string
    {
        $currency = $event->foreign_amount?->currency;

        return $currency;
    }

    /**
     * Resolve or create the Asset model for a given event.
     */
    private static function resolveAsset(AcbEventData $event): ?Asset
    {
        if (! $assetCode = self::getAssetCode($event)) {
            return null;
        }

        return Asset::firstOrCreate(
            ['asset_code' => $assetCode],
            [
                'acb_currency' => 'CAD',
                'precision' => self::inferPrecisionFromMoney($event),
                'acb' => Money::zero('CAD'),
                'quantity' => AssetQuantity::zero($assetCode),
                'total_proceeds' => Money::zero('CAD'),
                'total_cost' => Money::zero('CAD'),
            ]
        );
    }

    /**
     * Process an acquisition event and update asset ACB.
     */
    private static function processAcquisition(Asset $asset, AcbEventData $event): void
    {
        // Skip base currency (CAD) acquisitions
        if ($asset->isBaseCurrency()) {
            AcbEvent::create([
                'asset_code' => $asset->asset_code,
                'tx_id' => $event->tx_id,
                'event_at' => $event->tx_at,
                'event_type' => AcbEventType::Acquisition,
                'quantity' => AssetQuantity::zero('CAD'),
                'cost_amount' => $event->amount,
                'proceeds' => Money::zero('CAD'),
            ]);

            return;
        }

        // This should not happen, but there is no point in continuing if it does.
        if ($event->amount === null) {
            return;
        }

        $quantity = $event->foreign_amount
            ? AssetQuantity::fromMoney($event->foreign_amount, $asset)
            : AssetQuantity::zero($asset);

        $cost = $event->amount;

        $asset->applyAcquisition(
            quantity: $quantity,
            cost: $cost,
        );

        $asset->save();

        AcbEvent::create([
            'asset_code' => $asset->asset_code,
            'tx_id' => $event->tx_id,
            'event_at' => $event->tx_at,
            'event_type' => AcbEventType::Acquisition,
            'quantity' => $quantity,
            'cost_amount' => $cost,
            'proceeds' => Money::zero('CAD'),
        ]);
    }

    /**
     * Process a disposal event and update asset ACB.
     */
    private static function processDisposal(Asset $asset, AcbEventData $event): void
    {
        // Skip base currency (CAD) disposals
        if ($asset->isBaseCurrency()) {
            return;
        }

        $quantity = $event->foreign_amount
            ? AssetQuantity::fromMoney($event->foreign_amount, $asset)
            : AssetQuantity::zero($asset);

        $proceeds = $event->amount->abs();

        if ($quantity->isZero() || $proceeds->isZero()) {
            return;
        }

        $result = $asset->applyDisposal(
            quantity: $quantity->abs(),
            proceeds: $proceeds,
        );

        $asset->save();

        AcbEvent::create([
            'asset_code' => $asset->asset_code,
            'tx_id' => $event->tx_id,
            'event_at' => $event->tx_at,
            'event_type' => AcbEventType::Disposal,
            'quantity' => $quantity, // Should be negative
            'cost_amount' => $result->acb_allocated, // Should be positive
            'proceeds' => $proceeds, // Should be positive
        ]);
    }

    /**
     * Process a transfer fee event as a deemed disposition.
     */
    private static function processTransferFee(Asset $asset, AcbEventData $event): void
    {
        if (! $event->foreign_amount) {
            // CAD-only transfer fees are not ACB relevant
            return;
        }

        $quantity = AssetQuantity::fromMoney($event->foreign_amount, $asset);

        if ($quantity->isZero()) {
            return;
        }

        // Transfer fee = deemed disposition with zero proceeds
        $result = $asset->applyDisposal(
            quantity: $quantity,
            proceeds: Money::zero($asset->acb_currency)
        );

        $asset->save();

        AcbEvent::create([
            'asset_code' => $asset->asset_code,
            'tx_id' => $event->tx_id,
            'event_at' => $event->tx_at,
            'event_type' => AcbEventType::TransferFee,
            'quantity' => $quantity->negated(), // Should be negative
            'cost_amount' => $result->acb_allocated, // Should be positive
            'proceeds' => Money::zero($asset->acb_currency),
        ]);
    }

    /**
     * Infer asset precision from event money objects.
     *
     * @throws \LogicException
     */
    private static function inferPrecisionFromMoney(AcbEventData $event): int
    {
        $money = $event->foreign_amount;

        if (! $money) {
            throw new \LogicException('Cannot infer asset precision');
        }

        if (! $currency = CurrencyRegistry::get($money->currency)) {
            throw new \LogicException('Cannot infer asset precision');
        }

        return $currency->display_scale;
    }
}
