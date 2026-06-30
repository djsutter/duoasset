<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateStockBuySetup;
use App\Services\MarketData\MarketDataProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drive the Stock Buy Setup scanner: pull the FMP company-screener
 * pre-filtered by min_market_cap + exchanges, then dispatch one
 * EvaluateStockBuySetup per qualifying symbol. Mirrors the
 * ScanEpsRevisions pattern (per-symbol jobs + error surfacing).
 */
class ScanBuySetups extends Command
{
    protected $signature = 'stocks:scan-buy-setups
        {--symbol=* : restrict to one or more symbols (skips the screener)}
        {--limit= : override max symbols per run}
        {--sync : process synchronously (debug)}';

    protected $description = 'Scan FMP daily bars for high-volume spikes following tight consolidation bases.';

    public function handle(MarketDataProvider $provider): int
    {
        if (! config('market_data.buy_setup_scanner.enabled', true)) {
            $this->warn('Stock Buy Setup scanner is disabled (BUY_SETUP_SCANNER_ENABLED=false).');

            return self::SUCCESS;
        }

        $config = config('market_data.buy_setup_scanner');
        $minMcap = (int) ($config['min_market_cap'] ?? 100_000_000);
        $exchanges = (array) ($config['exchanges'] ?? []);
        $limit = (int) ($this->option('limit') ?? ($config['max_symbols_per_run'] ?? 0));

        if (method_exists($provider, 'clearErrors')) {
            $provider->clearErrors();
        }

        $explicit = array_filter((array) $this->option('symbol'));
        $rows = [];

        if (! empty($explicit)) {
            foreach ($explicit as $sym) {
                $rows[] = ['symbol' => strtoupper(trim((string) $sym))];
            }
        } else {
            $this->info("Loading FMP company-screener (marketCap >= {$minMcap}, exchanges: ".implode(',', $exchanges).')');
            $rows = $provider->companyScreener([
                'marketCapMoreThan' => $minMcap,
                'exchange' => $exchanges,
                'limit' => $limit > 0 ? $limit : null,
            ]);
            $this->info('Screener rows: '.count($rows));

            if (method_exists($provider, 'lastErrors')) {
                foreach ($provider->lastErrors() as $err) {
                    $hint = match ((int) ($err['status'] ?? 0)) {
                        401, 403 => ' (check FMP_API_KEY)',
                        402 => ' (your FMP plan does not allow this endpoint)',
                        429 => ' (rate limit hit)',
                        default => '',
                    };
                    $this->warn(sprintf(
                        'FMP %s returned HTTP %d%s: %s',
                        $err['path'] ?? '?',
                        (int) ($err['status'] ?? 0),
                        $hint,
                        trim((string) ($err['body'] ?? '')),
                    ));
                }
            }
        }

        if ($limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        $dispatched = 0;
        $sync = (bool) $this->option('sync');
        $verbose = $this->getOutput()->isVerbose();
        $startedAt = microtime(true);
        $summary = [
            'processed' => 0,
            'matched' => 0,
            'created' => 0,
            'existing' => 0,
            'rejected' => 0,
            'errors' => 0,
            'reasons' => [],
            'top' => [],
        ];

        if ($sync && ! $verbose) {
            $this->line('Scanning '.count($rows).' symbols...');
            $bar = $this->output->createProgressBar(count($rows));
            $bar->start();
        } else {
            $bar = null;
        }

        foreach ($rows as $index => $row) {
            $symbol = $row['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }

            $payload = [
                'symbol' => (string) $symbol,
                'companyName' => $row['company_name'] ?? null,
                'exchange' => $row['exchange'] ?? null,
                'marketCap' => isset($row['market_cap']) ? (int) $row['market_cap'] : null,
                'price' => isset($row['price']) && is_numeric($row['price']) ? (float) $row['price'] : null,
                'sharesOutstanding' => isset($row['shares_outstanding']) ? (int) $row['shares_outstanding'] : null,
                'floatShares' => isset($row['float_shares']) ? (int) $row['float_shares'] : null,
                'freeFloat' => isset($row['free_float']) && is_numeric($row['free_float']) ? (float) $row['free_float'] : null,
            ];

            if ($sync) {
                // Important: Dispatchable::dispatchSync() does not reliably return the
                // job handle() result for this queued job in all Laravel versions.
                // Verbose mode needs the structured debug array from handle(), so run
                // the job through the container directly for foreground sync scans.
                $job = new EvaluateStockBuySetup(
                    $payload['symbol'],
                    $payload['companyName'],
                    $payload['exchange'],
                    $payload['marketCap'],
                    $payload['price'],
                    $payload['sharesOutstanding'],
                    $payload['floatShares'],
                    $payload['freeFloat'],
                );
                $result = app()->call([$job, 'handle']);
                if (! is_array($result)) {
                    $result = [
                        'symbol' => $symbol,
                        'status' => 'unknown',
                        'reason' => 'sync job returned no debug result',
                    ];
                }

                $this->recordSyncResult($result, $summary);
                if ($verbose) {
                    $this->renderVerboseResult($index + 1, count($rows), $result);
                } elseif ($bar !== null) {
                    $bar->advance();
                }
            } else {
                EvaluateStockBuySetup::dispatch(...array_values($payload));
            }
            $dispatched++;
        }

        if (isset($bar) && $bar !== null) {
            $bar->finish();
            $this->newLine(2);
        }

        $this->info(($sync ? 'Processed' : 'Dispatched').": {$dispatched} per-symbol buy-setup evaluations.");

        if ($sync) {
            $this->renderSyncSummary($summary, microtime(true) - $startedAt);
        }

        if (! $sync) {
            $queueConnection = (string) config('queue.default');
            $this->line("Queue connection: {$queueConnection}");

            if ($queueConnection === 'database') {
                $queue = (string) config('queue.connections.database.queue', 'default');
                $pending = DB::table((string) config('queue.connections.database.table', 'jobs'))
                    ->where('queue', $queue)
                    ->where('payload', 'like', '%EvaluateStockBuySetup%')
                    ->count();

                $this->line("Pending {$queue} jobs for EvaluateStockBuySetup: {$pending}");
                $this->warn('Jobs are queued, not executed by this command. Run `php artisan queue:work --queue='.$queue.'` or rerun with `--sync` for a foreground debug pass.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $summary
     */
    private function recordSyncResult(array $result, array &$summary): void
    {
        $summary['processed']++;
        $status = (string) ($result['status'] ?? 'unknown');

        if ($status === 'matched') {
            $summary['matched']++;
        } elseif ($status === 'error') {
            $summary['errors']++;
        } else {
            $summary['rejected']++;
        }

        $reason = (string) ($result['reason'] ?? '');
        if ($reason !== '') {
            $summary['reasons'][$reason] = ($summary['reasons'][$reason] ?? 0) + 1;
        }

        foreach ((array) ($result['matches'] ?? []) as $match) {
            $matchStatus = (string) ($match['status'] ?? '');
            if ($matchStatus === 'created') {
                $summary['created']++;
            } elseif ($matchStatus === 'existing') {
                $summary['existing']++;
            }

            if (isset($match['setup_score'])) {
                $summary['top'][] = [
                    'symbol' => (string) ($result['symbol'] ?? ''),
                    'setup_type' => (string) ($match['setup_type'] ?? ''),
                    'score' => (int) ($match['setup_score'] ?? 0),
                    'status' => $matchStatus,
                ];
            }
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function renderVerboseResult(int $index, int $total, array $result): void
    {
        $symbol = (string) ($result['symbol'] ?? '?');
        $status = (string) ($result['status'] ?? 'unknown');
        $elapsed = (int) ($result['elapsed_ms'] ?? 0);

        $this->newLine();
        $this->line(str_repeat('-', 72));
        $this->line(sprintf('%d/%d  %s  [%s]  %d ms', $index, $total, $symbol, strtoupper($status), $elapsed));
        $this->line(sprintf(
            'Bars: %d total, %d existing, %d fetched | Benchmark: %d | Fundamentals: %s',
            (int) ($result['bars'] ?? 0),
            (int) ($result['existing_bars'] ?? 0),
            (int) ($result['fetched_bars'] ?? 0),
            (int) ($result['benchmark_bars'] ?? 0),
            ! empty($result['fundamentals_loaded']) ? 'yes' : 'no',
        ));

        if ($status !== 'matched') {
            $this->warn('Reject: '.((string) ($result['reason'] ?? 'no reason reported')));
        }

        foreach ((array) ($result['matches'] ?? []) as $match) {
            $this->line(sprintf(
                '✔ %s | setup_score=%s/100 raw=%s/%s spike=%s notify=%s',
                (string) ($match['setup_type'] ?? 'setup'),
                (string) ($match['setup_score'] ?? '—'),
                (string) ($match['raw_score'] ?? '—'),
                (string) ($match['max_score'] ?? '—'),
                (string) ($match['spike_date'] ?? '—'),
                ! empty($match['notification_eligible']) ? 'yes' : 'no (< '.(string) ($match['notify_min_score'] ?? '—').')',
            ));

            if (isset($match['base_days'])) {
                $this->line(sprintf(
                    '  base=%sd range=%s%% ATR=%s dist_to_bo=%s%% RS=%s',
                    (string) ($match['base_days'] ?? '—'),
                    (string) ($match['range_pct'] ?? '—'),
                    (string) ($match['atr_ratio'] ?? '—'),
                    (string) ($match['distance_to_breakout_pct'] ?? '—'),
                    (string) ($match['relative_strength'] ?? '—'),
                ));
            }
            if (array_key_exists('liquidity_penalty_pct', $match)) {
                $this->line(sprintf(
                    '  liquidity avg_vol=%s turnover=%s%% penalty=%s%% (-%s pts)',
                    $match['avg_daily_volume'] !== null ? number_format((int) $match['avg_daily_volume']) : '—',
                    $match['liquidity_turnover_pct'] !== null ? number_format((float) $match['liquidity_turnover_pct'], 6) : '—',
                    number_format((float) ($match['liquidity_penalty_pct'] ?? 0), 2),
                    (string) ($match['liquidity_penalty_points'] ?? 0),
                ));
            }


            if (! empty($match['score_breakdown']) && is_array($match['score_breakdown'])) {
                $this->line('  Score components:');
                foreach ($match['score_breakdown'] as $component) {
                    if (! is_array($component)) {
                        continue;
                    }
                    $label = (string) ($component['label'] ?? 'Component');
                    $points = (int) ($component['points'] ?? 0);
                    $max = (int) ($component['max'] ?? 0);
                    $value = (string) ($component['value'] ?? '');
                    $penalty = max(0, $max - $points);
                    $this->line(sprintf(
                        '    %-22s %3d/%-3d  penalty=%-3d  value=%s',
                        $label,
                        $points,
                        $max,
                        $penalty,
                        $value,
                    ));
                }
            }

            if (! empty($match['reason'])) {
                $this->line('  Summary: '.$match['reason']);
            }
        }
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function renderSyncSummary(array $summary, float $elapsedSeconds): void
    {
        $this->newLine();
        $this->line(str_repeat('=', 72));
        $this->info('Buy setup scan summary');
        $this->line('Processed: '.$summary['processed']);
        $this->line('Matched:   '.$summary['matched'].'  (created '.$summary['created'].', existing '.$summary['existing'].')');
        $this->line('Rejected:  '.$summary['rejected']);
        $this->line('Errors:    '.$summary['errors']);
        $this->line('Elapsed:   '.$this->formatElapsed($elapsedSeconds));

        if (! empty($summary['reasons'])) {
            arsort($summary['reasons']);
            $this->newLine();
            $this->line('Top rejection reasons:');
            foreach (array_slice($summary['reasons'], 0, 10, true) as $reason => $count) {
                $this->line(sprintf('  %5d  %s', $count, $reason));
            }
        }

        if (! empty($summary['top'])) {
            usort($summary['top'], fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $this->newLine();
            $this->line('Top setup matches:');
            foreach (array_slice($summary['top'], 0, 10) as $row) {
                $this->line(sprintf(
                    '  %3d  %-12s %-36s %s',
                    (int) ($row['score'] ?? 0),
                    (string) ($row['symbol'] ?? ''),
                    (string) ($row['setup_type'] ?? ''),
                    (string) ($row['status'] ?? ''),
                ));
            }
        }
    }

    private function formatElapsed(float $seconds): string
    {
        if ($seconds < 60) {
            return number_format($seconds, 1).'s';
        }

        $minutes = floor($seconds / 60);
        $remaining = $seconds - ($minutes * 60);

        return sprintf('%dm %02ds', $minutes, (int) round($remaining));
    }

}
