<?php

namespace App\Domain\Tax\Disposition;

use App\Domain\Tax\Pool\PoolDispositionResult;
use App\Domain\Tax\Pool\PoolState;
use App\Models\AcbEvent;
use App\Services\Tax\SuperficialLossEvaluator;

final class DispositionPolicyApplier
{
    public function __construct(
        private SuperficialLossEvaluator $superficialEvaluator,
    ) {}

    public function apply(PoolDispositionResult $mechanical, AcbEvent $event): PoolDispositionResult
    {
        $gainOrLoss = $mechanical->preliminaryGainOrLoss;

        if (! $gainOrLoss->isNegative() || $event->isTransferFee()) {
            return $mechanical;
        }

        $capitalLoss = $gainOrLoss->abs();

        $evaluation = $this->superficialEvaluator->evaluate(
            disposition: $event,
            unitsDisposed: $mechanical->unitsDisposed,
            capitalLossBeforeDenial: $capitalLoss,
            unitsRemainingAfterDisposition: $mechanical->newPoolState->quantity,
        );

        $deniedLoss = $evaluation->deniedLoss;

        $adjustedAcb = $mechanical->newPoolState->acb;

        if ($deniedLoss->isPositive()) {
            $adjustedAcb = $adjustedAcb->add($deniedLoss);
        }

        return new PoolDispositionResult(
            newPoolState: new PoolState(
                $mechanical->newPoolState->quantity,
                $adjustedAcb,
            ),
            acbAllocated: $mechanical->acbAllocated,
            preliminaryGainOrLoss: $mechanical->preliminaryGainOrLoss,
            finalGainOrLoss: $evaluation->allowableLoss,
            deniedLoss: $deniedLoss,
            unitsDisposed: $mechanical->unitsDisposed,
        );
    }
}
