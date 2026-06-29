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

        foreach ($rows as $row) {
            $symbol = $row['symbol'] ?? null;
            if (! $symbol) {
                continue;
            }

            $payload = [
                'symbol' => (string) $symbol,
                'companyName' => $row['company_name'] ?? null,
                'exchange' => $row['exchange'] ?? null,
                'marketCap' => isset($row['market_cap']) ? (int) $row['market_cap'] : null,
            ];

            if ($sync) {
                EvaluateStockBuySetup::dispatchSync(...array_values($payload));
            } else {
                EvaluateStockBuySetup::dispatch(...array_values($payload));
            }
            $dispatched++;
        }

        $this->info("Dispatched: {$dispatched} per-symbol buy-setup evaluations.");

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
}
