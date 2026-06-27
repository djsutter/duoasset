<?php

use App\Jobs\ValuationCatchUp;
use App\Models\AcbEvent;
use App\Models\PooledSuperficialAllocation;
use App\Models\TaxPoolDisposition;
use App\Services\Tax\TaxPoolLedgerBuilder;
use App\Types\AssetQuantity;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ValuationCatchUp)->everyFifteenMinutes();

// Earnings surprise scanner — every 5 minutes during market/earnings hours.
Schedule::command('earnings:scan-surprises')
    ->weekdays()
    ->timezone('America/Toronto')
    ->between('06:00', '18:00')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Additional evening sweep for late-filed reports.
Schedule::command('earnings:scan-surprises')
    ->weekdays()
    ->timezone('America/Toronto')
    ->dailyAt('20:30')
    ->withoutOverlapping();

// EPS Revision scanner — twice per day (pre-market + post-close, ET).
Schedule::command('earnings:scan-revisions')
    ->weekdays()
    ->timezone('America/Toronto')
    ->twiceDaily(7, 17)
    ->withoutOverlapping()
    ->runInBackground();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sched3b', function () {
    $taxYear = 2021; // change as needed

    // Get all assets with dispositions this year
    $assetCodes = TaxPoolDisposition::query()
        ->whereYear('disposition_date', $taxYear)
        ->distinct()
        ->pluck('asset_code');

    $poolBuilder = app(\App\Services\Tax\PoolBasedSchedule3Builder::class);

    foreach ($assetCodes as $assetCode) {
        echo "\n=== Asset: {$assetCode} (Year {$taxYear}) ===\n";

        // 1️⃣ Pool Ledger totals
        $ledger = app(TaxPoolLedgerBuilder::class)
            ->buildForAssetUpToDate($assetCode, CarbonImmutable::create($taxYear, 12, 31)->endOfDay());

        $ledgerProceeds = Money::zero('CAD');
        $ledgerAcb = Money::zero('CAD');
        $ledgerGain = Money::zero('CAD');

        foreach ($ledger as $entry) {
            if ($entry->event_type === \App\Enums\TaxPoolLedgerEntryType::Disposition
                && $entry->event_date->year === $taxYear
            ) {
                $ledgerProceeds = $ledgerProceeds->add($entry->proceeds);
                $ledgerAcb = $ledgerAcb->add($entry->acb_allocated);
                $ledgerGain = $ledgerGain->add($entry->gain_loss);
            }
        }

        echo "Ledger Totals -> Proceeds: {$ledgerProceeds->format()}, ACB: {$ledgerAcb->format()}, Gain: {$ledgerGain->format()}\n";

        // 2️⃣ Schedule 3 totals
        $assetRow = $poolBuilder->buildAssetRow($taxYear, $assetCode);

        echo "Schedule3 Totals -> Proceeds: {$assetRow->proceeds->format()}, ACB: {$assetRow->acb->format()}, Gain: {$assetRow->gain->format()}\n";

        // 3️⃣ Compare differences
        $diffProceeds = $assetRow->proceeds->subtract($ledgerProceeds);
        $diffAcb = $assetRow->acb->subtract($ledgerAcb);
        $diffGain = $assetRow->gain->subtract($ledgerGain);

        if (! $diffProceeds->isZero() || ! $diffAcb->isZero() || ! $diffGain->isZero()) {
            echo ">>> Difference -> Proceeds: {$diffProceeds->format()}, ACB: {$diffAcb->format()}, Gain: {$diffGain->format()}\n";
        }
    }
})->purpose('Display an inspiring quote');

Artisan::command('sched3a', function () {
    $taxYear = 2021; // change as needed
    $assetCodes = TaxPoolDisposition::query()
        ->whereYear('disposition_date', $taxYear)
        ->distinct()
        ->pluck('asset_code');

    foreach ($assetCodes as $assetCode) {
        echo "\n=== Ledger for asset: {$assetCode} (Year {$taxYear}) ===\n";

        $ledger = app(TaxPoolLedgerBuilder::class)
            ->buildForAssetUpToDate($assetCode, CarbonImmutable::create($taxYear, 12, 31)->endOfDay());

        $acbRunning = Money::zero('CAD');
        $quantityRunning = AssetQuantity::zero($assetCode);

        foreach ($ledger as $entry) {
            $eventType = $entry->event_type->value;
            $date = $entry->event_date->format('Y-m-d');
            $qtyAfter = $entry->quantity_after;
            $acbAfter = $entry->acb_after;
            $deniedLoss = $entry->denied_loss ?? Money::zero('CAD');
            $gainLoss = $entry->gain_loss ?? Money::zero('CAD');

            echo "[{$date}] {$eventType} | Qty after: {$qtyAfter->format()} | ACB after: {$acbAfter->format()} | Denied loss: {$deniedLoss->format()} | Gain/loss: {$gainLoss->format()}\n";

            // optional sanity check: track running totals
            $quantityRunning = $qtyAfter;
            $acbRunning = $acbAfter;
        }

        echo "Final pool totals: Qty={$quantityRunning->format()}, ACB={$acbRunning->format()}\n";
    }
})->purpose('Debugging for sched 3');

Artisan::command('acb-reset', function () {
    \App\Models\Lot::truncate();
    \App\Models\LotDisposition::truncate();
    \App\Tax\SuperficialLoss\Persistence\PendingSuperficialLossModel::truncate();
    \App\Models\SuperficialLossEvent::truncate();
    PooledSuperficialAllocation::truncate();
    TaxPoolDisposition::truncate();
    \App\Models\TaxPool::truncate();
})->purpose('DB reset for ACB tests');

Artisan::command('sftest', function () {
    $assets = TaxPoolDisposition::query()
        ->select('asset_code')
        ->distinct()
        ->pluck('asset_code');

    foreach ($assets as $asset) {
        $totalDenied = TaxPoolDisposition::where('asset_code', $asset)
            ->where('capital_gain', '<', 0)
            ->get()
            ->reduce(fn ($carry, $d) => $carry + $d->capital_gain->abs()->toDecimal(), 0);

        $totalAllocated = AcbEvent::where('asset_code', $asset)
            ->where('adjustment_reason', 'superficial_loss_denied')
            ->get()
            ->reduce(fn ($carry, $e) => $carry + $e->cost_amount->toDecimal(), 0);

        $allocations = PooledSuperficialAllocation::where('asset_code', $asset)
            ->get()
            ->map(fn ($a) => "{$a->disposition_id}: {$a->allocated_units->format()}")
            ->implode(', ');

        echo "Asset: {$asset}\n";
        echo "  Total denied: {$totalDenied}\n";
        echo "  Total allocated in AcbEvent: {$totalAllocated}\n";
        echo "  Allocation per disposition: {$allocations}\n\n";
    }
});

// Schedule::command('tax:resolve-superficial-losses')->hourly()->withoutOverlapping();
