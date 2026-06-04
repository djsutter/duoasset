<?php

namespace App\Console\Commands;

use App\Models\AcbEvent;
use App\Models\LotDisposition;
use App\Services\Tax\TaxService;
use App\Types\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;

class GenerateCapitalGainsReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'acb:report:capital-gains
    {--from= : Start date (YYYY-MM-DD)}
    {--to= : End date (YYYY-MM-DD)}
    {--schedule3 : Export CRA Schedule 3}
    {--year= : Tax year (required with --schedule3)}
    {--csv= : CSV file path for output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate CRA-ready capital gains report (read-only)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('schedule3')) {
            $year = $this->option('year');
            if (! $year) {
                throw new \InvalidArgumentException('--year is required when using --schedule3');
            }

            if ($this->option('from') || $this->option('to')) {
                throw new \InvalidArgumentException('--from/--to cannot be used with --schedule3; use --year instead');
            }
        } else {
            $from = $this->option('from')
                ? new CarbonImmutable($this->option('from'))
                : null;

            $to = $this->option('to')
                ? new CarbonImmutable($this->option('to'))
                : null;
        }

        if ($this->option('schedule3')) {
            $taxService = app(TaxService::class);
            $csvPath = $this->option('csv') ?? "schedule3_{$year}.csv";
            $rows = $taxService->oldSchedule3Data($year);
            $this->exportCsv($csvPath, $rows);

            return Command::SUCCESS;
        }

        $query = LotDisposition::query()
            ->with(['lot'])
            ->orderBy('id');

        if ($from) {
            $query->where('disposed_at', '>=', $from);
        }
        if ($to) {
            $query->where('disposed_at', '<=', $to);
        }

        $rows = [];

        foreach ($query->get() as $disposition) {
            $rows[] = $this->buildRow($disposition);
        }

        if ($csvPath = $this->option('csv')) {
            $this->renderCsv($rows, $csvPath);
        } else {
            $this->renderTable($rows);
        }

        return Command::SUCCESS;
    }

    private function buildRow(LotDisposition $disposition): array
    {
        $lot = $disposition->lot;
        $event = AcbEvent::findOrFail($disposition->acb_event_id);

        $disposalDate = CarbonImmutable::parse($event->event_at);

        // Use persisted ACB allocated
        $allocatedAcb = $disposition->acb_allocated;

        // Gain / loss
        $proceeds = $disposition->proceeds ?? Money::zero($allocatedAcb->currency);
        $gainOrLoss = $proceeds->subtract($allocatedAcb);
        $deniedLoss = $disposition->denied_loss_amount ?? Money::zero($allocatedAcb->currency);

        // Superficial loss flag
        $hasSuperficialLoss =
            $gainOrLoss->isNegative()
            && $deniedLoss->isPositive();

        return [
            'date' => $disposalDate->toDateString(),
            'asset' => $lot->asset_code,
            'quantity' => $disposition->disposed_quantity->toDecimal(),
            'proceeds' => $proceeds->toDecimal(),
            'acb' => $allocatedAcb->toDecimal(),
            'gain_loss' => $gainOrLoss->toDecimal(),
            'superficial' => $hasSuperficialLoss ? 'yes' : 'no',
            'tx_id' => $event->tx_id,
        ];
    }

    private function renderTable(array $rows): void
    {
        $table = new Table($this->output);

        $table->setHeaders([
            'Date',
            'Asset',
            'Quantity',
            'Proceeds',
            'ACB',
            'Gain/Loss',
            'Superficial',
            'Tx ID',
        ]);

        foreach ($rows as $row) {
            $table->addRow([
                $row['date'],
                $row['asset'],
                $row['quantity'],
                $row['proceeds'],
                $row['acb'],
                $row['gain_loss'],
                $row['superficial'],
                $row['tx_id'],
            ]);
        }

        $table->render();
    }

    private function renderCsv(array $rows, string $path): void
    {
        $handle = $path === '-'
            ? fopen('php://output', 'w')
            : fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV output: {$path}");
        }

        // Header row — explicit order is critical
        fputcsv($handle, [
            'Date',
            'Asset',
            'Quantity',
            'Proceeds',
            'ACB',
            'Gain/Loss',
            'Superficial',
            'Tx ID',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['date'],
                $row['asset'],
                $row['quantity'],
                $row['proceeds'],
                $row['acb'],
                $row['gain_loss'],
                $row['superficial'],
                $row['tx_id'],
            ]);
        }

        fclose($handle);
    }

    private function exportCsv(string $filePath, array $rows): void
    {
        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file: $filePath");
        }

        // Write header
        fputcsv($handle, array_keys($rows[0]));

        // Write rows
        foreach ($rows as $row) {
            fputcsv($handle, array_values($row));
        }

        fclose($handle);

        $this->info("Schedule 3 CSV exported to $filePath");
    }
}
