<?php

namespace App\Console\Commands;

use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use App\Models\Lot;
use App\Models\LotDisposition;
use App\Tax\SuperficialLoss\Domain\PendingSuperficialLoss;
use App\Tax\SuperficialLoss\Persistence\PendingSuperficialLossModel;
use App\Types\AssetQuantity;
use App\Types\Money;
use App\Types\MoneySum;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class MaterializeLotDispositionsCommand extends Command
{
    protected $signature = 'acb:materialize-dispositions';

    public function handle(): int
    {
        $events = AcbEvent::query()
            ->where('event_type', AcbEventType::Disposal)
            ->orderBy('event_at')
            ->get();

        foreach ($events as $event) {
            $this->applyDisposalEvent($event);
        }

        return self::SUCCESS;
    }

    private function applyDisposalEvent(AcbEvent $event): void
    {
        // Sanity guard
        if (! $event->quantity->isNegative()) {
            throw new \LogicException("Disposal event {$event->id} quantity must be negative");
        }

        if ($event->proceeds === null || $event->proceeds->isNegative()) {
            throw new \LogicException("Disposal event {$event->id} proceeds must be positive");
        }

        $eventQtyAbs = $event->quantity->abs();

        // Already disposed quantity
        $alreadyDisposedDecimal = LotDisposition::query()
            ->where('acb_event_id', $event->id)
            ->sum('disposed_quantity');

        $alreadyDisposed = new AssetQuantity($alreadyDisposedDecimal, $event->asset_code);

        if ($alreadyDisposed->equals($eventQtyAbs)) {
            // Already fully materialized
            return;
        }

        /** @var AssetQuantity $remainingToDispose */
        $remainingToDispose = $eventQtyAbs->subtract($alreadyDisposed);

        /** @var \Illuminate\Support\Collection<int, Lot> $lots */
        $lots = Lot::query()
            ->where('asset_code', $event->asset_code)
            ->orderBy('acquired_at')
            ->get();

        foreach ($lots as $lot) {
            // Determine how much we can take from this lot
            $existingDisposition = LotDisposition::query()
                ->where('lot_id', $lot->id)
                ->where('acb_event_id', $event->id)
                ->first();

            $previouslyTaken = $existingDisposition
                ? $existingDisposition->disposed_quantity
                : AssetQuantity::zero($event->asset_code);

            // Event-specific remaining capacity of this lot
            $availableForThisEvent = $lot->remaining_quantity->subtract($previouslyTaken);

            if ($availableForThisEvent->isZero()) {
                continue;
            }

            // Single authoritative allocation
            $taken = AssetQuantity::min([$remainingToDispose, $availableForThisEvent]);

            if ($taken->isZero()) {
                continue;
            }

            assert($taken->isPositive());
            $allocatedAcb = $lot->unitCost()->multiplyByQuantity($taken);
            $fraction = bcdiv($taken->toDecimal(), $eventQtyAbs->toDecimal(), 12);
            $allocatedProceeds = $event->proceeds->multiply($fraction);

            LotDisposition::updateOrCreate(
                [
                    'lot_id' => $lot->id,
                    'acb_event_id' => $event->id,
                ],
                [
                    'asset_code' => $event->asset_code,
                    'disposed_quantity' => $taken,
                    'proceeds' => $allocatedProceeds,
                    'proceeds_currency' => $event->proceeds->currency,
                    'disposed_at' => $event->event_at,
                    'acb_allocated' => $allocatedAcb,
                    'denied_loss_amount' => Money::zero($allocatedAcb->currency),
                ]
            );

            // Apply the SAME delta everywhere
            $lot->remaining_quantity = $lot->remaining_quantity->subtract($taken);
            $lot->save();

            $remainingToDispose = $remainingToDispose->subtract($taken);

            if ($remainingToDispose->isZero()) {
                break;
            }
        }

        if (! $remainingToDispose->isZero()) {
            throw new \LogicException(
                sprintf(
                    'Insufficient inventory for disposal event %d (%s remaining)',
                    $event->id,
                    $remainingToDispose->toDecimal()
                )
            );
        }

        // -----------------------------
        // CREATE PENDING SUPERFICIAL LOSS
        // -----------------------------
        // Compute total ACB and proceeds for this disposal

        $sumAcb = new MoneySum('CAD');
        $sumProceeds = new MoneySum('CAD');

        LotDisposition::query()
            ->where('acb_event_id', $event->id)
            ->each(function ($disp) use (&$sumAcb, &$sumProceeds) {
                $sumAcb->add($disp->acb_allocated);
                $sumProceeds->add($disp->proceeds);
            });

        $gainLoss = $sumProceeds->toMoney()->subtract($sumAcb->toMoney());

        if ($gainLoss->isNegative() && ! $eventQtyAbs->isZero()) {
            $pending = PendingSuperficialLoss::createFromDisposition(
                acbEventId: $event->id,
                assetCode: $event->asset_code,
                superficialLoss: $gainLoss->abs(),
                superficialUnits: $eventQtyAbs,
                dispositionDate: CarbonImmutable::parse($event->event_at),
            );

            if ($pending !== null) {
                PendingSuperficialLossModel::fromDomain($pending)->save();
            }
        }
    }
}
