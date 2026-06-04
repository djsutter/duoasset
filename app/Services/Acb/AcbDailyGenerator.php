<?php

namespace App\Services\Acb;

use App\Models\AcbDaily;
use App\Models\AcbEvent;
use App\Models\Asset;
use App\Services\PriceService;
use App\Support\CurrencyRegistry;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\Carbon;

class AcbDailyGenerator
{
    private PriceService $priceService;

    private array $dustLogged = [];

    public function __construct()
    {
        $this->priceService = app(PriceService::class);
    }

    /**
     * Build daily snapshots for all assets that have events.
     */
    public function buildAll(): void
    {
        $assets = AcbEvent::select('asset_code')
            ->distinct()
            ->pluck('asset_code');

        foreach ($assets as $assetCode) {
            $this->buildForAsset($assetCode);
        }
    }

    /**
     * Build daily snapshots for a single asset.
     */
    public function buildForAsset(string $assetCode): void
    {
        $asset = Asset::where('asset_code', $assetCode)->firstOrFail();

        $quantity = AssetQuantity::zero($asset);
        $runningAcb = Money::zero($asset->acb_currency);
        $cadZero = Money::zero('CAD'); // Cached instance for reuse

        $events = AcbEvent::where('asset_code', $assetCode)
            ->orderBy('event_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        $currentDate = null;

        foreach ($events as $event) {
            $eventDate = $event->event_at->format('Y-m-d');

            // Flush previous day's snapshot if date changed
            if ($currentDate && $currentDate !== $eventDate) {
                $this->flushDailySnapshot($asset, $currentDate, $quantity, $runningAcb, $cadZero);
            }

            $currentDate = $eventDate;

            // Convert event cost to Money
            $eventCost = $this->moneyFromDecimal($event->cost_amount, $asset->acb_currency);

            // Update quantity
            $quantity = $quantity->add(
                $event->quantity->withDirection($event->event_type->quantityDirection())
            );

            // Update ACB if event affects it
            if ($event->event_type->affectsAcb()) {
                $runningAcb = $runningAcb->add(
                    $eventCost->withDirection($event->event_type->quantityDirection())
                );
            }
        }

        // Persist final day's snapshot
        if ($currentDate) {
            $this->flushDailySnapshot($asset, $currentDate, $quantity, $runningAcb, $cadZero);
        }

        // Reconciliation - verify final balance
        $this->reconcileFinalDaily($asset);
    }

    private function flushDailySnapshot(
        Asset $asset,
        string $date,
        AssetQuantity $quantity,
        Money $runningAcb
    ): void {
        // Step 1: Clamp negative quantity
        if ($quantity->isNegative()) {
            $quantity = AssetQuantity::zero($asset);
            $snapshotAcb = Money::zero($asset->acb_currency);
        } elseif ($quantity->isZero()) {
            $snapshotAcb = Money::zero($asset->acb_currency);
        } else {
            $snapshotAcb = $runningAcb->round(2);
        }

        // Step 2: Apply dust threshold
        $snapshotAcb = $this->applyDustIfNeeded($quantity, $snapshotAcb, $asset->asset_code, Carbon::parse($date), $dustApplied);

        if ($dustApplied && empty($this->dustLogged[$asset->asset_code])) {
            \Log::info("ACB dust normalization applied for {$asset->asset_code}");
            $this->dustLogged[$asset->asset_code] = true;
        }

        // Step 3: Persist
        $this->persistDaily($asset, $date, $quantity, $snapshotAcb);
    }

    private function applyDustIfNeeded(
        AssetQuantity $quantity,
        Money $runningAcb,
        string $assetCode,
        Carbon $date,
        ?bool &$dustApplied = null,
    ): Money {
        $dustApplied = false;

        // Hard invariant: no inventory → no ACB
        if ($quantity->isZero() || $quantity->isNegative()) {
            $dustApplied = true;

            return Money::zero('CAD');
        }

        /*
         * 1) UNIT FLOOR (representational dust)
         */
        $unitFloor = $this->unitFloorFor($assetCode);

        if (bccomp($quantity->amount, $unitFloor, 18) < 0) {
            $dustApplied = true;

            return Money::zero('CAD');
        }

        /*
         * 2) ECONOMIC DUST (CAD-value based)
         */
        $rate = $this->priceService->getCadPrice($assetCode, $date);

        $cadValue = Money::fromDecimal($quantity->toMoney()->multiply($rate)->toDecimal(), 'CAD')
            ->round(2);

        if ($cadValue->abs()->lessThanOrEqualTo(Money::fromDecimal('0.01', 'CAD'))) {
            $dustApplied = true;

            return Money::zero('CAD');
        }

        /*
         * Otherwise: retain ACB
         */
        return $runningAcb;
    }

    private function unitFloorFor(string $assetCode): string
    {
        $scale = CurrencyRegistry::getDisplayScale($assetCode);

        // 10 ^ -scale, expressed as decimal string
        return bcdiv('1', bcpow('10', (string) $scale, 0), $scale);
    }

    /**
     * Helper: reconcile final daily record against asset totals
     */
    private function reconcileFinalDaily(Asset $asset): void
    {
        $finalDaily = AcbDaily::where('asset_code', $asset->asset_code)
            ->orderByDesc('date')
            ->first();

        if (! $finalDaily) {
            return;
        }

        $dailyQty = $finalDaily->quantity_total;
        $dailyAcb = $finalDaily->acb_total;

        if (! $dailyQty->equals($asset->quantity)) {
            \Log::warning(sprintf(
                'ACB reconciliation failure (quantity) for asset %s: daily=%s asset=%s',
                $asset->asset_code,
                $dailyQty->amount,
                $asset->quantity->amount
            ));
        }

        if (! $dailyAcb->equals($asset->acb)) {
            \Log::warning(sprintf(
                'ACB reconciliation failure (acb) for asset %s: daily=%s asset=%s',
                $asset->asset_code,
                $dailyAcb->toDecimal(),
                $asset->acb->toDecimal()
            ));
        }
    }

    /**
     * Persist or update AcbDaily for the given date and Money states.
     */
    protected function persistDaily(Asset $asset, string $date, AssetQuantity $quantity, Money $acb): void
    {
        $avg = '0';

        if (! $quantity->isZero()) {
            $avg = bcdiv(
                $acb->toDecimal(),      // decimal CAD
                $quantity->amount,     // decimal units
                18
            );
        }

        AcbDaily::updateOrCreate(
            [
                'asset_code' => $asset->asset_code,
                'date' => $date,
            ],
            [
                'quantity_total' => $quantity,
                'acb_total' => $acb,
                'avg_cost_basis' => Money::fromDecimal($avg, 'CAD'),
            ]
        );
    }

    /**
     * Helper: create Money from decimal string for provided currency.
     */
    protected function moneyFromDecimal($value, string $currency): Money
    {
        if ($value instanceof Money) {
            return $value;
        }

        // IMPORTANT: decide by schema, not guesswork
        return Money::fromDecimal((string) $value, $currency);
    }
}
