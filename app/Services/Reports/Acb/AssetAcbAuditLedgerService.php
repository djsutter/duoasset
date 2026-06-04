<?php

namespace App\Services\Reports\Acb;

use App\Data\Reports\AssetAcbAuditRowData;
use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use App\Models\Asset;
use App\Services\Reports\Acb\Events\AppliedAcbEvent;
use App\Services\Reports\Acb\Events\AssetAcbEvent;
use App\Types\AssetQuantity;
use App\Types\Money;
use LogicException;

final class AssetAcbAuditLedgerService
{
    protected Asset $asset;

    // Strict mode will throw exceptions on invalid events (mostly negative quantities).
    protected bool $strict = true;

    protected CapitalGainsOptions $options;

    protected SuperficialLossAdjustmentProcessor $superficialLossProcessor;

    public function __construct(SuperficialLossAdjustmentProcessor $superficialLossProcessor)
    {
        $this->options = new CapitalGainsOptions;
        $this->superficialLossProcessor = $superficialLossProcessor;
    }

    public function forAsset(Asset $asset): self
    {
        $this->asset = $asset;

        return $this;
    }

    public function withOptions(CapitalGainsOptions $options): self
    {
        $this->options = $options;

        return $this;
    }

    /** @return AssetAcbAuditRowData[] */
    public function build(): AssetAcbAuditLedgerResult
    {
        if (! isset($this->asset)) {
            throw new LogicException('Asset not configured for ACB audit ledger');
        }

        $events = $this->loadAssetEvents();

        $quantity = AssetQuantity::zero($this->asset);
        $acb = Money::zero('CAD');

        $rows = [];

        foreach ($events as $event) {
            $appliedEvent = $this->applyEvent(
                event: $event,
                quantity: $quantity,
                acb: $acb,
            );

            $rows[] = $appliedEvent->row;
            $quantity = $appliedEvent->quantity;
            $acb = $appliedEvent->acb;

            if ($this->strict && $quantity->isNegative()) {
                throw new LogicException('Asset quantity became negative during ACB audit');
            }
        }

        $ledger = new AssetAcbAuditLedgerResult($rows);

        if ($this->options->applySuperficialLoss) {
            $result = $this->superficialLossProcessor->process($ledger);
            $ledger = $result->ledger;
        }

        if (! $this->options->explainSuperficialLoss) {
            return $ledger;
        }

        $diagnostics = [];

        foreach ($result->deniedLossByRow as $rowKey => $money) {
            $diagnostics[$rowKey] = [
                'latent_superficial_loss' => $money,
            ];
        }

        return new AssetAcbAuditLedgerResult(
            rows: $ledger->rows,
            annotations: $ledger->annotations,
            diagnostics: $diagnostics,
        );
    }

    protected function loadAssetEvents(): array
    {
        return AcbEvent::query()
            ->where('asset_code', $this->asset->asset_code)
            ->orderBy('event_at')
            ->orderBy('id')
            ->get()
            ->map(function (AcbEvent $event) {
                return new AssetAcbEvent(
                    event_at: $event->event_at,
                    event_type: $event->event_type,
                    tx_id: $event->tx_id,
                    quantity: $event->quantity,
                    cost_amount: $event->cost_amount,
                    proceeds: $event->proceeds,
                );
            })
            ->all();
    }

    protected function applyEvent(AssetAcbEvent $event, AssetQuantity $quantity, Money $acb): AppliedAcbEvent
    {
        return match ($event->event_type) {
            AcbEventType::Acquisition, AcbEventType::Adjustment => $this->applyAcquisition($event, $quantity, $acb),
            AcbEventType::Disposal => $this->applyDisposal($event, $quantity, $acb),
            AcbEventType::TransferFee => $this->applyTransferFee($event, $quantity, $acb),
            default => throw new LogicException("Unhandled event type: $event->event_type"),
        };
    }

    private function applyAcquisition(AssetAcbEvent $event, AssetQuantity $quantity, Money $acb): AppliedAcbEvent
    {
        $this->assertEventAmountPresent($event);
        $this->assertEventAmountNotNegative($event);
        $this->assertEventQuantityPresent($event);
        $this->assertEventQuantityNotNegative($event);

        $quantityAfter = $quantity->add($event->quantity);
        $acbAfter = $acb->add($event->cost_amount);

        return new AppliedAcbEvent(
            row: new AssetAcbAuditRowData(
                event_at: $event->event_at,
                event_type: $event->event_type,
                tx_id: $event->tx_id,

                quantity_change: $event->quantity,
                quantity_after: $quantityAfter,

                acb_change: $event->cost_amount,
                acb_after: $acbAfter,
                unit_cost: $event->cost_amount->divide($event->quantity->toDecimal()),

                proceeds: Money::zero($acb->currency),
                acb_allocated: Money::zero($acb->currency),
                capital_gain_loss: Money::zero($acb->currency),
            ),
            quantity: $quantityAfter,
            acb: $acbAfter,
        );
    }

    private function applyDisposal(AssetAcbEvent $event, AssetQuantity $quantity, Money $acb): AppliedAcbEvent
    {
        $this->assertEventProceedsPositive($event);
        $this->assertEventQuantityNegative($event);

        if ($quantity->isZero()) {
            throw new LogicException('Disposition with zero quantity');
        }

        $absQuantity = $event->quantity->abs();

        if ($this->strict && $absQuantity->greaterThan($quantity)) {
            throw new LogicException('Disposition exceeds available quantity for TxId '.$event->tx_id);
        }

        $acbPerUnit = $acb->divide($quantity->toDecimal());
        $acbAllocated = $acbPerUnit->multiply($absQuantity->toDecimal());
        $proceeds = $event->proceeds->abs();

        $quantityAfter = $quantity->subtract($absQuantity);
        $acbAfter = $acb->subtract($acbAllocated);

        $capitalGainLoss = $proceeds->subtract($acbAllocated);

        return new AppliedAcbEvent(
            row: new AssetAcbAuditRowData(
                event_at: $event->event_at,
                event_type: $event->event_type,
                tx_id: $event->tx_id,

                quantity_change: $event->quantity,
                quantity_after: $quantityAfter,

                acb_change: $acbAllocated->negated(),
                acb_after: $acbAfter,
                unit_cost: $acbAllocated->divideByQuantity($event->quantity),

                proceeds: $proceeds,
                acb_allocated: $acbAllocated,
                capital_gain_loss: $capitalGainLoss,
            ),
            quantity: $quantityAfter,
            acb: $acbAfter,
        );
    }

    public function applyTransferFee(AssetAcbEvent $event, AssetQuantity $quantity, Money $acb): AppliedAcbEvent
    {
        /*
         * Reduces quantity
         * Reduces ACB proportionally
         * No proceeds
         * No gain/loss
         */
        $this->assertEventQuantityNegative($event);

        if ($quantity->isZero()) {
            throw new LogicException('Transfer fee with zero quantity');
        }

        $absQuantity = $event->quantity->abs();

        if ($this->strict && $absQuantity->greaterThan($quantity)) {
            throw new LogicException('Fees exceed available quantity for TxId '.$event->tx_id);
        }

        $acbPerUnit = $acb->divide($quantity->toDecimal());
        $acbAllocated = $acbPerUnit->multiplyByQuantity($absQuantity);
        $acbAfter = $acb->subtract($acbAllocated);
        $quantityAfter = $quantity->subtract($absQuantity);

        return new AppliedAcbEvent(
            row: new AssetAcbAuditRowData(
                event_at: $event->event_at,
                event_type: $event->event_type,
                tx_id: $event->tx_id,

                quantity_change: $event->quantity,
                quantity_after: $quantityAfter,

                acb_change: $acbAllocated->negated(),
                acb_after: $acbAfter,
                unit_cost: $acbAllocated->divideByQuantity($absQuantity),

                proceeds: Money::zero($acb->currency),
                acb_allocated: Money::zero($acb->currency),
                capital_gain_loss: Money::zero($acb->currency),
            ),
            quantity: $quantityAfter,
            acb: $acbAfter,
        );
    }

    private function assertEventAmountPresent(AssetAcbEvent $event): void
    {
        if (! $event->cost_amount) {
            throw new LogicException("Event {$event->event_type->value} missing amount");
        }
    }

    private function assertEventProceedsPositive(AssetAcbEvent $event): void
    {
        if (! ($event->proceeds && $event->proceeds->isPositive())) {
            throw new LogicException("Event {$event->event_type->value} proceeds must be positive");
        }
    }

    private function assertEventProceedsPresent(AssetAcbEvent $event): void
    {
        if (! $event->proceeds) {
            throw new LogicException("Event {$event->event_type->value} missing amount");
        }
    }

    private function assertEventAmountNotNegative(AssetAcbEvent $event): void
    {
        if ($this->strict && $event->cost_amount->isNegative()) {
            throw new LogicException("Event {$event->event_type->value} with negative amount");
        }
    }

    public function assertEventAmountNotZero(AssetAcbEvent $event): void
    {
        if ($event->cost_amount->isZero()) {
            throw new LogicException("Event {$event->event_type->value} with zero amount");
        }
    }

    private function assertEventQuantityPresent(AssetAcbEvent $event): void
    {
        if (! $event->quantity) {
            throw new LogicException("Event {$event->event_type->value} missing quantity");
        }
    }

    private function assertEventQuantityNegative(AssetAcbEvent $event): void
    {
        if ($this->strict && ! $event->quantity->isNegative()) {
            throw new LogicException("Event {$event->event_type->value} with non-negative quantity");
        }
    }

    private function assertEventQuantityNotNegative(AssetAcbEvent $event): void
    {
        if ($this->strict && $event->quantity->isNegative()) {
            throw new LogicException("Event {$event->event_type->value} with negative quantity");
        }
    }

    public function assertEventQuantityNotZero(AssetAcbEvent $event): void
    {
        if ($event->quantity->isZero()) {
            throw new LogicException("Event {$event->event_type->value} with zero quantity");
        }
    }

    public function assertEventQuantityNotGreaterThan(AssetAcbEvent $event, $availableQuantity): void
    {
        if ($this->strict && $event->quantity->greaterThan($availableQuantity)) {
            throw new LogicException("Event {$event->event_type->value} exceeds available quantity");
        }
    }
}
