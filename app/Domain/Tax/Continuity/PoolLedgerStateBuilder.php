<?php

namespace App\Domain\Tax\Continuity;

use App\Services\Tax\TaxPoolLedgerBuilder;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

final class PoolLedgerStateBuilder implements TaxAssetStateBuilderInterface
{
    public function __construct(
        protected TaxPoolLedgerBuilder $ledgerBuilder,
    ) {}

    public function buildUpToDate(
        string $assetCode,
        Carbon $date
    ): AssetStateSnapshot {
        $ledger = $this->ledgerBuilder->buildForAssetUpToDate(
            $assetCode,
            CarbonImmutable::instance($date)
        );

        $last = null;

        foreach ($ledger as $entry) {
            $last = $entry;
        }

        if (! $last) {
            return new AssetStateSnapshot(
                quantity: AssetQuantity::zero($assetCode),
                acb: Money::zero(getReportingCurrency()),
            );
        }

        return new AssetStateSnapshot(
            quantity: $last->quantity_after,
            acb: $last->acb_after,
        );
    }

    public function buildBetweenDates(
        string $assetCode,
        Carbon $start,
        Carbon $end
    ): AssetActivitySnapshot {

        $reportingCurrency = getReportingCurrency();

        $ledger = $this->ledgerBuilder->buildForAssetUpToDate(
            $assetCode,
            CarbonImmutable::instance($end)
        );

        $quantityAcquired = AssetQuantity::zero($assetCode);
        $quantityDisposed = AssetQuantity::zero($assetCode);

        $acbAdded = Money::zero($reportingCurrency);
        $acbAllocated = Money::zero($reportingCurrency);
        $proceeds = Money::zero($reportingCurrency);
        $acbOfDispositions = Money::zero($reportingCurrency);
        $realizedGainBeforeDenial = Money::zero($reportingCurrency);
        $deniedLoss = Money::zero($reportingCurrency);

        foreach ($ledger as $entry) {

            if ($entry->event_date->lt($start)) {
                continue;
            }

            if ($entry->event_date->gt($end)) {
                break;
            }

            // -----------------------------
            // Acquisitions
            // -----------------------------
            if ($entry->quantity_delta->isPositive()) {
                $quantityAcquired = $quantityAcquired->add($entry->quantity_delta);
                $acbAdded = $acbAdded->add($entry->acb_delta);
            }

            // -----------------------------
            // Dispositions
            // -----------------------------
            if ($entry->quantity_delta->isNegative()) {

                $quantityDisposed = $quantityDisposed->add($entry->quantity_delta->abs());

                $entryProceeds = $entry->proceeds ?? Money::zero($reportingCurrency);
                $entryAcbAllocated = $entry->acb_allocated ?? Money::zero($reportingCurrency);
                $entryGainBeforeDenial = $entry->capital_gain_loss_before_denial ?? Money::zero($reportingCurrency);

                // Applied portion of superficial loss = acb_allocated - (proceeds - capital_gain_loss_before_denial)
                $appliedLoss = $entryAcbAllocated->subtract($entryProceeds->subtract($entryGainBeforeDenial));

                // Adjusted ACB of dispositions for reporting
                $adjustedAcb = $entryAcbAllocated->subtract($appliedLoss);

                $acbOfDispositions = $acbOfDispositions->add($adjustedAcb);

                // Net gain = proceeds - adjusted ACB
                $realizedGainBeforeDenial = $realizedGainBeforeDenial->add(
                    $entryProceeds->subtract($adjustedAcb)
                );

                // Denied losses (superficial)
                if ($entry->denied_loss !== null) {
                    $deniedLoss = $deniedLoss->add($entry->denied_loss);
                }

                // Total proceeds
                $proceeds = $proceeds->add($entryProceeds);
                $acbAllocated = $acbAllocated->add($entry->acb_allocated);
            }
        }

        $acbReportable = $acbAllocated->subtract($deniedLoss);
        $gainOrLoss = $proceeds->subtract($acbReportable);

        return new AssetActivitySnapshot(
            quantityAcquired: $quantityAcquired,
            acbAdded: $acbAdded,
            quantityDisposed: $quantityDisposed,
            proceeds: $proceeds,
            acbOfDispositions: $acbOfDispositions,
            realizedGainBeforeDenial: $realizedGainBeforeDenial,
            deniedLoss: $deniedLoss,
            acbReportable: $acbReportable,
            gainOrLoss: $gainOrLoss,
        );
    }

    public function getActiveAssets(): array
    {
        return ['BTC'];
        // return $this->ledgerBuilder->getActiveAssets();
    }
}
