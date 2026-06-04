<?php

namespace App\Console\Commands;

use App\Models\PriceHistory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use League\Csv\Reader;

class LoadHistoricalPrices extends Command
{
    protected $signature = 'load-historical-prices';

    protected $description = 'Load historical price data from CSV files in /historical-data';

    public function handle()
    {
        //        $this->info("Calculating missing CAD prices...");
        //        $this->fillMissingCadValues();
        //        return;

        $basePath = base_path('historical-data');

        if (! File::isDirectory($basePath)) {
            $this->error("Folder not found: $basePath");

            return Command::FAILURE;
        }

        // ------------------------------
        // 1. Load all *-usd-max.csv files
        // ------------------------------

        $files = File::glob($basePath.'/*-usd-max.csv');

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            $currency = strtoupper(strtok($filename, '-'));

            $this->info("Loading $filename (currency: $currency)");

            $this->loadStandardHistoricalFile($filePath, $currency);
        }

        // ------------------------------
        // 2. Load special usd-cad.csv file
        // ------------------------------

        $usdCadFile = $basePath.'/usd-cad.csv';

        if (File::exists($usdCadFile)) {
            $this->info('Loading usd-cad.csv (currency: USD)');
            $this->loadUsdCadFile($usdCadFile);
        } else {
            $this->warn('usd-cad.csv not found.');
        }

        // ------------------------------
        // 3. Fill missing CAD conversions
        // ------------------------------

        $this->info('Calculating missing CAD prices...');
        $this->fillMissingCadValues();

        $this->info('Loading complete.');

        return Command::SUCCESS;
    }

    /**
     * Load normal *-usd-max.csv files, filling date gaps.
     */
    protected function loadStandardHistoricalFile(string $filePath, string $currency)
    {
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);

        $lastDate = null;
        $lastUsd = null;

        foreach ($csv->getRecords() as $record) {

            $date = Carbon::parse($record['snapped_at']);
            $dateStr = $date->toDateString();
            $usd = $record['price'];

            // Fill missing days
            if ($lastDate) {
                $expectedNextDay = $lastDate->copy()->addDay();

                if ($date->greaterThan($expectedNextDay)) {
                    $this->fillMissingDays($lastDate, $date, $currency, $lastUsd, 'usd');
                }
            }

            PriceHistory::updateOrCreate(
                ['date' => $dateStr, 'currency' => $currency],
                ['usd' => $usd, 'cad' => null]
            );

            $lastDate = $date;
            $lastUsd = $usd;
        }
    }

    /**
     * Load USD→CAD exchange rates, filling date gaps.
     */
    protected function loadUsdCadFile(string $filePath)
    {
        $csv = Reader::createFromPath($filePath, 'r');
        $csv->setHeaderOffset(0);

        $currency = 'USD';
        $lastDate = null;
        $lastCad = null;

        foreach ($csv->getRecords() as $record) {

            $date = Carbon::parse($record['Date']);
            $dateStr = $date->toDateString();
            $cad = $record['Price'];

            if ($lastDate) {
                $expected = $lastDate->copy()->addDay();

                if ($date->greaterThan($expected)) {
                    $this->fillMissingDays($lastDate, $date, $currency, $lastCad, 'cad');
                }
            }

            PriceHistory::updateOrCreate(
                ['date' => $dateStr, 'currency' => $currency],
                ['usd' => null, 'cad' => $cad]
            );

            $lastDate = $date;
            $lastCad = $cad;
        }
    }

    /**
     * Fill date gaps with most recent price.
     */
    protected function fillMissingDays(Carbon $from, string $toDate, string $currency, $lastValue, string $column)
    {
        $current = $from->copy()->addDay();
        $end = Carbon::parse($toDate);

        while ($current->lte($end->copy()->subDay())) {

            PriceHistory::updateOrCreate(
                ['date' => $current->toDateString(), 'currency' => $currency],
                [
                    'usd' => $column === 'usd' ? $lastValue : null,
                    'cad' => $column === 'cad' ? $lastValue : null,
                ]
            );

            $current->addDay();
        }
    }

    /**
     * After import: calculate missing CAD using USD×rate.
     */
    public function fillMissingCadValues()
    {
        // Load full USD->CAD map into memory for fast lookups
        $usdCadMap = PriceHistory::where('currency', 'USD')
            ->pluck('cad', 'date')
            ->toArray();

        // All rows where cad is null and usd is not null
        $rows = PriceHistory::whereNull('cad')
            ->whereNotNull('usd')
            ->orderBy('date')
            ->get();

        foreach ($rows as $row) {
            $usdToCad = $usdCadMap[$row->date] ?? null;

            if (! $usdToCad) {
                continue; // no rate available for that day
            }

            // ensure both values are valid decimals
            $usd = (string) $row->usd;
            $rate = (string) $usdToCad;

            // multiply using bcmul but fix scientific notation first
            if (str_contains($rate, 'e')) {
                $rate = sprintf('%.12f', (float) $rate);
            }

            if (str_contains($usd, 'e')) {
                $usd = sprintf('%.12f', (float) $usd);
            }

            $cad = bcmul($usd, $rate, 12);

            $row->cad = $cad;
            $row->save();
        }
    }

    public function normalizeDecimal(string $value): string
    {
        // Trim whitespace just in case
        $value = trim($value);

        // If it contains scientific notation (e.g. "8.74e-05")
        if (stripos($value, 'e') !== false) {
            // Convert using PHP’s float → string conversion with high precision
            // If you want even more precision, adjust the sprintf format.
            $value = sprintf('%.20F', (float) $value);
            // Remove trailing zeros
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }
}
