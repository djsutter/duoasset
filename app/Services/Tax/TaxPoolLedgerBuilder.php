<?php

namespace App\Services\Tax;

use App\Data\Tax\TaxPoolLedgerEntryData;
use App\Enums\TaxPoolLedgerEntryType;
use App\Models\AcbEvent;
use App\Models\TaxPoolDisposition;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class TaxPoolLedgerBuilder
{
    private AssetQuantity $currentQuantity;

    private Money $currentAcb;

    private string $assetCode;

    private Collection $dispositionsByEventId;

    public function initialize(
        string $assetCode,
        Collection $dispositionsByEventId
    ): void {
        $this->assetCode = $assetCode;
        $this->dispositionsByEventId = $dispositionsByEventId;

        $this->resetState();
    }

    public function build(iterable $events): iterable
    {
        $this->resetState();

        foreach ($events as $event) {
            yield from $this->handleEvent($event);
        }
    }

    private function resetState(): void
    {
        $this->currentQuantity = AssetQuantity::zero($this->assetCode);
        $this->currentAcb = Money::zero(getReportingCurrency());
    }

    private function handleEvent(AcbEvent $event): iterable
    {
        yield match ($event->getTaxEventType()) {
            TaxPoolLedgerEntryType::Acquisition => $this->handleAcquisition($event),
            TaxPoolLedgerEntryType::Disposition => $this->handleDisposition($event),
            default => throw new \RuntimeException(
                'Unhandled AcbEventType: '.var_export($event->event_type, true)
            ),
        };
    }

    private function handleAcquisition(AcbEvent $event): TaxPoolLedgerEntryData
    {
        $quantityDelta = $event->quantity;
        $acbDelta = $event->cost_amount;

        $this->currentQuantity = $this->currentQuantity->add($quantityDelta);
        $this->currentAcb = $this->currentAcb->add($acbDelta);

        return $this->createLedgerEntry(
            TaxPoolLedgerEntryType::Acquisition,
            $event,
            $quantityDelta,
            $acbDelta
        );
    }

    private function handleDisposition(AcbEvent $event): TaxPoolLedgerEntryData
    {
        if ($this->currentQuantity->isZero()) {
            throw new \RuntimeException(
                "Cannot dispose asset {$this->assetCode} with zero pool quantity."
            );
        }

        /** @var TaxPoolDisposition $taxDisposition */
        $taxDisposition = $this->dispositionsByEventId->get($event->id);

        if (! $taxDisposition) {
            throw new \RuntimeException(
                "Missing tax_pool_disposition for AcbEvent {$event->id}"
            );
        }

        $quantityDisposed = $taxDisposition->quantity_disposed->abs();
        $acbAllocated = $taxDisposition->acb_allocated;
        $acbReportable = $acbAllocated;
        $proceeds = $taxDisposition->proceeds;

        if ($taxDisposition->capital_gain_loss_before_denial === null) {
            throw new \RuntimeException(
                "Missing capital_gain_loss_before_denial for disposition {$event->id}"
            );
        }

        $capitalGainLossBeforeDenial = $taxDisposition->capital_gain_loss_before_denial;
        $deniedLoss = $taxDisposition->denied_loss_amount;
        $capitalGainLossAfterDenial = $taxDisposition->capital_gain_loss_after_denial;

        // Compute deltas
        $quantityDelta = $quantityDisposed->negated();
        $acbDelta = $acbAllocated->negated();

        // Mutate state
        $this->currentQuantity = $this->currentQuantity->add($quantityDelta);
        $this->currentAcb = $this->currentAcb->add($acbDelta);

        // Apply denied loss to the running pool immediately
        if ($deniedLoss?->isPositive()) {
            $this->currentAcb = $this->currentAcb->add($deniedLoss);
            $acbReportable = $acbReportable->subtract($deniedLoss);
        }

        // Defensive normalization: if quantity hits zero, force ACB to zero
        if ($this->currentQuantity->isZero()) {
            $this->currentAcb = Money::zero(getReportingCurrency());
        }

        return $this->createLedgerEntry(
            type: TaxPoolLedgerEntryType::Disposition,
            event: $event,
            quantityDelta: $quantityDelta,
            acbDelta: $acbDelta,
            proceeds: $proceeds,
            acbAllocated: $acbAllocated,
            acbReportable: $acbReportable,
            capitalGainLossBeforeDenial: $capitalGainLossBeforeDenial,
            deniedLoss: $deniedLoss,
            capitalGainLossAfterDenial: $capitalGainLossAfterDenial,
        );
    }

    private function createLedgerEntry(
        TaxPoolLedgerEntryType $type,
        AcbEvent $event,
        AssetQuantity $quantityDelta,
        Money $acbDelta,
        ?Money $proceeds = null,
        ?Money $acbAllocated = null,
        ?Money $acbReportable = null,
        ?Money $capitalGainLossBeforeDenial = null,
        ?Money $deniedLoss = null,
        ?Money $capitalGainLossAfterDenial = null,
    ): TaxPoolLedgerEntryData {
        $quantityAfter = $this->currentQuantity;
        $acbAfter = $this->currentAcb;

        $unitCostAfter = $quantityAfter->isZero()
            ? null
            : $acbAfter->divideByQuantity($quantityAfter);

        return new TaxPoolLedgerEntryData(
            tx_id: $event->tx_id,
            asset_code: $this->assetCode,
            currency: getReportingCurrency(),
            event_type: $type,
            origin_event_type: $event->event_type,
            source_event_id: $event->id,
            event_date: CarbonImmutable::parse($event->event_at),
            quantity_delta: $quantityDelta,
            quantity_after: $quantityAfter,
            acb_delta: $acbDelta,
            acb_after: $acbAfter,
            unit_cost_after: $unitCostAfter,
            proceeds: $proceeds,
            acb_allocated: $acbAllocated,
            acb_reportable: $acbReportable,
            capital_gain_loss_before_denial: $capitalGainLossBeforeDenial,
            denied_loss: $deniedLoss,
            capital_gain_loss_after_denial: $capitalGainLossAfterDenial,
        );
    }

    /**
     * Build ledger entries for an asset up to a given date.
     *
     * @return iterable<TaxPoolLedgerEntryData>
     */
    public function buildForAssetUpToDate(string $assetCode, CarbonImmutable $toDate): iterable
    {
        $events = AcbEvent::query()
            ->where('asset_code', $assetCode)
            ->where('event_at', '<=', $toDate)
            ->orderBy('event_at')
            ->get();

        $dispositionsByEventId = TaxPoolDisposition::query()
            ->where('asset_code', $assetCode)
            ->where('disposition_date', '<=', $toDate)
            ->get()
            ->keyBy(fn ($disp) => $disp->acb_event_id);

        $this->initialize($assetCode, $dispositionsByEventId);

        return $this->build($events);
    }
}
