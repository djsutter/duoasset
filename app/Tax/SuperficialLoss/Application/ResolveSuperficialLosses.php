<?php

namespace App\Tax\SuperficialLoss\Application;

use App\Models\LotDisposition;
use App\Models\SuperficialLossEvent;
use App\Tax\Events\AcquisitionEvent;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use App\Tax\SuperficialLoss\Domain\SuperficialLossResolver;
use App\Tax\SuperficialLoss\Persistence\PendingSuperficialLossModel;
use Carbon\CarbonImmutable;

final class ResolveSuperficialLosses
{
    public function __construct(
        private SuperficialLossResolver $resolver,
    ) {}

    /**
     * Resolve a set of acquisitions against pending losses.
     *
     * @param  AcquisitionEvent[]  $acquisitions
     */
    public function run(array $acquisitions, CarbonImmutable $asOf): void
    {
        SuperficialLossEvent::truncate();

        // Sort acquisitions by date
        $acquisitions = collect($acquisitions)
            ->sortBy(fn (AcquisitionEvent $a) => $a->date)
            ->values();

        // Load all pending superficial losses
        $models = PendingSuperficialLossModel::query()->get();

        foreach ($models as $model) {
            // Rehydrate domain object
            /** @var PendingSuperficialLoss $loss */
            $loss = $model->toDomain();

            // PHASE 1: apply acquisition-based denials
            $relevantAcquisitions = $acquisitions->filter(
                fn (AcquisitionEvent $a) => $a->assetCode === $loss->assetCode
                // Optional but recommended:
                // && $a->date->between($loss->windowStart, $loss->windowEnd)
            );

            foreach ($relevantAcquisitions as $acquisition) {
                $this->resolver->resolveForAcquisition($acquisition, $loss);
            }

            // PHASE 2: expire leftovers AFTER matching
            if ($loss->status()->canExpire()) {
                $loss->expireIfNeeded($asOf);
            }

            // Persist denied loss to lot_dispositions
            $lotDisposition = LotDisposition::where('acb_event_id', $loss->acbEventId)->firstOrFail();
            $lotDisposition->denied_loss_amount = $loss->deniedLoss();
            $lotDisposition->save();

            // Persist pending_superficial_losses
            $pendingLoss = PendingSuperficialLossModel::findOrFail($loss->id->toString());
            $pendingLoss->remaining_loss_amount = $loss->remainingLossAmount;
            $pendingLoss->remaining_units = $loss->remainingUnits;
            $pendingLoss->status = $loss->status()->value;
            $pendingLoss->expired_at = $loss->expiredAt;
            $pendingLoss->save();

            // originalLossAmount: total loss before any denial
            $capitalLossBeforeDenial = $loss->originalLossAmount;

            // deniedLoss: the portion that was denied
            $deniedLoss = $loss->originalLossAmount->subtract($loss->remainingLossAmount);

            // allowableLoss: the portion that actually applies to Schedule 3
            $allowableLoss = $loss->remainingLossAmount;

            if ($loss->remainingLossAmount->isZero() && $loss->originalLossAmount->isPositive()) {
                $reasonCode = 'fully_denied';
            } elseif ($loss->remainingLossAmount->lessThan($loss->originalLossAmount)) {
                $reasonCode = 'partially_denied';
            } else {
                $reasonCode = 'none';
            }

            SuperficialLossEvent::create([
                'acb_event_id' => $loss->acbEventId,
                'capital_loss_before_denial' => $capitalLossBeforeDenial,
                'denied_loss_amount' => $deniedLoss,
                'allowable_loss_amount' => $allowableLoss,
                'window_start' => $loss->windowStart,
                'window_end' => $loss->windowEnd,
                'reason_code' => $reasonCode,
                'resolution_type' => $loss->status()->value, // added_to_acb | pending | expired
                'replacement_acb_event_id' => null,
            ]);
        }
    }
}
