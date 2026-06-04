<?php

namespace App\Domain\Tax\Pool;

use App\Models\AcbEvent;
use App\Models\TaxPool;
use App\Models\TaxPoolDisposition;
use App\Types\Money;
use LogicException;

final class PoolManager
{
    /**
     * @var array<string, PoolState>
     */
    private array $pools = [];

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    public function reset(): void
    {
        TaxPoolDisposition::truncate();
        TaxPool::truncate();
        $this->pools = [];
    }

    public function finalize(): void
    {
        foreach ($this->pools as $assetCode => $state) {
            TaxPool::create([
                'asset_code' => $assetCode,
                'total_quantity' => $state->quantity,
                'total_acb' => $state->acb,
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | State Access
    |--------------------------------------------------------------------------
    */

    private function getOrCreateState(string $assetCode): PoolState
    {
        if (! isset($this->pools[$assetCode])) {
            $this->pools[$assetCode] = PoolState::empty($assetCode);
        }

        return $this->pools[$assetCode];
    }

    /*
    |--------------------------------------------------------------------------
    | Mutations
    |--------------------------------------------------------------------------
    */

    public function processAcquisition(AcbEvent $event): void
    {
        $assetCode = $event->asset_code;
        $state = $this->getOrCreateState($assetCode);

        $newQuantity = $state->quantity->add($event->quantity);
        $newAcb = $state->acb->add($event->cost_amount);

        $this->pools[$assetCode] = new PoolState($newQuantity, $newAcb);
    }

    public function processDisposition(AcbEvent $event): PoolDispositionResult
    {
        $assetCode = $event->asset_code;
        $state = $this->getOrCreateState($assetCode);

        if ($state->quantity->isZero()) {
            throw new LogicException('Disposition with zero pool quantity.');
        }

        $currency = getReportingCurrency();
        $unitsDisposed = $event->quantity->abs();

        $acbPerUnit = $state->acb->divideByQuantity($state->quantity);
        $acbAllocated = $acbPerUnit->multiplyByQuantity($unitsDisposed);

        $newQuantity = $state->quantity->subtract($unitsDisposed);
        $newAcb = $state->acb->subtract($acbAllocated);

        if ($newQuantity->isZero()) {
            $newAcb = Money::zero($currency);
        }

        $preliminaryGainOrLoss = $event->proceeds->subtract($acbAllocated);

        return new PoolDispositionResult(
            newPoolState: new PoolState($newQuantity, $newAcb),
            acbAllocated: $acbAllocated,
            preliminaryGainOrLoss: $preliminaryGainOrLoss,
            finalGainOrLoss: $preliminaryGainOrLoss, // unchanged for now
            deniedLoss: Money::zero($currency),
            unitsDisposed: $unitsDisposed,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------------
    */

    public function commitDisposition(AcbEvent $event, PoolDispositionResult $result): void
    {
        $assetCode = $event->asset_code;

        $this->persistDisposition($event, $result);

        $this->pools[$assetCode] = $result->newPoolState;
    }

    private function persistDisposition(AcbEvent $event, PoolDispositionResult $result): void
    {
        TaxPoolDisposition::create([
            'acb_event_id' => $event->id,
            'origin_event_type' => $event->event_type,
            'asset_code' => $event->asset_code,
            'currency' => getReportingCurrency(),
            'quantity_disposed' => $result->unitsDisposed,
            'proceeds' => $event->proceeds,
            'acb_allocated' => $result->acbAllocated,
            'capital_gain_loss_before_denial' => $result->preliminaryGainOrLoss,
            'denied_loss_amount' => $result->deniedLoss,
            'capital_gain_loss_after_denial' => $result->finalGainOrLoss,
            'disposition_date' => $event->event_at->toImmutable(),
        ]);
    }
}
