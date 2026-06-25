<?php

namespace App\Console\Commands;

use App\Jobs\EnrichEarningsEvent;
use App\Models\EarningsEvent;
use App\Services\Earnings\EarningsSurpriseScorer;
use App\Services\MarketData\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanEarningsSurprises extends Command
{
    protected $signature = 'earnings:scan-surprises
        {--from= : YYYY-MM-DD (default today - 1)}
        {--to= : YYYY-MM-DD (default today + 1)}
        {--force : reprocess existing records}';

    protected $description = 'Scan FMP earnings calendar/surprises and upsert qualifying events.';

    public function handle(MarketDataProvider $provider, EarningsSurpriseScorer $scorer): int
    {
        if (! config('market_data.earnings_scanner.enabled', true)) {
            $this->warn('Earnings scanner is disabled (EARNINGS_SCANNER_ENABLED=false).');

            return self::SUCCESS;
        }

        $from = CarbonImmutable::parse($this->option('from') ?: CarbonImmutable::today()->subDay())->startOfDay();
        $to = CarbonImmutable::parse($this->option('to') ?: CarbonImmutable::today()->addDay())->endOfDay();
        $force = (bool) $this->option('force');

        $this->info("Scanning earnings from {$from->toDateString()} to {$to->toDateString()}");

        // 1) Earnings surprises (preferred — has actuals + computed surprise).
        $surprises = [];
        try {
            $surprises = $provider->earningsSurprises($from, $to);
        } catch (Throwable $e) {
            Log::error('earnings.surprises_failed', ['msg' => $e->getMessage()]);
        }
        $this->info('Surprise rows: '.count($surprises));

        // 2) Earnings calendar (covers events that don't have a surprise row yet).
        $calendar = [];
        try {
            $calendar = $provider->earningsCalendar($from, $to);
        } catch (Throwable $e) {
            Log::error('earnings.calendar_failed', ['msg' => $e->getMessage()]);
        }
        $this->info('Calendar rows: '.count($calendar));

        $merged = $this->mergeRows($surprises, $calendar);

        $processed = 0;
        $dispatched = 0;

        foreach ($merged as $row) {
            try {
                // Require actuals on either side to be meaningful.
                $epsActual = $row['eps_actual'] ?? null;
                $epsEstimated = $row['eps_estimated'] ?? null;

                if ($epsActual === null || $epsEstimated === null) {
                    continue;
                }

                // Surprise percent: prefer provider value, else compute.
                $surprisePct = $row['eps_surprise_percent'] ?? null;
                if ($surprisePct === null) {
                    $surprisePct = EarningsSurpriseScorer::calculateSurprisePercent(
                        (float) $epsActual,
                        (float) $epsEstimated,
                    );
                }
                if ($surprisePct === null) {
                    continue;
                }

                // Pre-filter by threshold to avoid persisting noise — but keep
                // some headroom: store events that beat a slightly lower bar
                // so the UI can show "near-misses" via min filter.
                $minPct = (float) config('market_data.earnings_scanner.min_eps_surprise_percent', 90);
                if ((float) $surprisePct < min(50.0, $minPct)) {
                    // Below even the "interesting" lower bound — skip storage.
                    continue;
                }

                $surpriseAbs = ($row['eps_surprise'] ?? null);
                if ($surpriseAbs === null) {
                    $surpriseAbs = (float) $epsActual - (float) $epsEstimated;
                }

                $event = EarningsEvent::updateOrCreate(
                    [
                        'source' => 'fmp',
                        'symbol' => $row['symbol'],
                        'report_date' => $row['report_date'],
                    ],
                    array_filter([
                        'company_name' => $row['company_name'] ?? null,
                        'exchange' => $row['exchange'] ?? null,
                        'report_time' => $row['report_time'] ?? null,
                        'fiscal_period' => $row['fiscal_period'] ?? null,
                        'eps_estimated' => $epsEstimated,
                        'eps_actual' => $epsActual,
                        'eps_surprise' => $surpriseAbs,
                        'eps_surprise_percent' => round((float) $surprisePct, 4),
                        'revenue_estimated' => $row['revenue_estimated'] ?? null,
                        'revenue_actual' => $row['revenue_actual'] ?? null,
                        'raw' => $row['raw'] ?? null,
                        'detected_at' => now(),
                    ], fn ($v) => $v !== null),
                );

                $revEst = $row['revenue_estimated'] ?? null;
                $revAct = $row['revenue_actual'] ?? null;
                if ($revEst && $revAct && (int) $revEst !== 0) {
                    $event->revenue_surprise_percent = round(
                        (((int) $revAct - (int) $revEst) / abs((int) $revEst)) * 100,
                        4,
                    );
                    $event->save();
                }

                $processed++;

                // Skip enrichment if alert already exists (idempotent), unless --force.
                if (! $force && $event->alert()->exists()) {
                    continue;
                }

                EnrichEarningsEvent::dispatch($event->id);
                $dispatched++;
            } catch (Throwable $e) {
                Log::error('earnings.row_failed', [
                    'symbol' => $row['symbol'] ?? null,
                    'msg' => $e->getMessage(),
                ]);
                // Continue with next row — never fail the whole command.
            }
        }

        $this->info("Processed: $processed, enrichment jobs dispatched: $dispatched");

        return self::SUCCESS;
    }

    /**
     * Merge surprise + calendar rows keyed by symbol+date.
     * Surprise data wins on overlap (it has actuals + provider's surprise%).
     */
    protected function mergeRows(array $surprises, array $calendar): array
    {
        $map = [];

        foreach ($calendar as $row) {
            $key = $row['symbol'].'|'.$row['report_date'];
            $map[$key] = $row;
        }

        foreach ($surprises as $row) {
            $key = $row['symbol'].'|'.$row['report_date'];
            $map[$key] = array_merge($map[$key] ?? [], array_filter($row, fn ($v) => $v !== null));
        }

        return array_values($map);
    }
}
