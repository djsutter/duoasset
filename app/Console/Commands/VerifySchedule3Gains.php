<?php

namespace App\Console\Commands;

use App\Data\Tax\Schedule3\Schedule3AssetData;
use App\Services\Tax\PoolBasedSchedule3Builder;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class VerifySchedule3Gains extends Command
{
    protected $signature = 'tax:verify-schedule3-gains
                            {--year=* : Specific tax years to check, leave empty for all years}';

    protected $description = 'Verify that Schedule 3 asset-level gain matches sum of disposition-level gains';

    public function handle()
    {
        $years = $this->option('year');

        if (empty($years)) {
            $years = range(2018, CarbonImmutable::now()->year); // adjust earliest year as needed
        }

        $this->info('Verifying Schedule 3 gains for years: '.implode(', ', $years));

        $totalErrors = 0;

        foreach ($years as $year) {
            // You may have a query to fetch all distinct assets for this year
            $assets = \DB::table('acb_events')
                ->whereYear('event_at', $year)
                ->select('asset_code')
                ->distinct()
                ->pluck('asset_code');

            foreach ($assets as $assetCode) {
                /** @var Schedule3AssetData $assetRow */
                $assetRow = app(PoolBasedSchedule3Builder::class)
                    ->buildAssetRow($year, $assetCode);

                $sumOfDispositions = collect($assetRow->dispositions)
                    ->reduce(fn ($carry, $d) => $carry->add($d->gain), Money::zero(getReportingCurrency()));

                if (! $assetRow->gain->equals($sumOfDispositions)) {
                    $this->error("Mismatch for {$year} / {$assetCode}: asset.gain = {$assetRow->gain}, sum of dispositions = {$sumOfDispositions}");
                    $totalErrors++;
                }
            }
        }

        if ($totalErrors === 0) {
            $this->info('All asset gains match sum of disposition gains ✅');
        } else {
            $this->warn("Found {$totalErrors} mismatches ❌");
        }

        return $totalErrors === 0 ? 0 : 1;
    }
}
