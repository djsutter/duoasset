<?php

namespace App\Console\Commands;

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use App\Services\MarketData\PersistentMarketDataProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeMarketDataDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market-data:purge-database
                            {--symbol= : Specific ticker symbol (e.g. AAPL)}
                            {--type=all : Data category to permanently delete (all, bars, financials, profile, quote, intraday, estimates, screener, earnings)}
                            {--force : Bypass confirmation safeguards}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently delete market data records from the database repository (Administrator safeguard)';

    /**
     * Command aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = [
        'market-data:purge',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $symbol = $this->option('symbol') ? strtoupper(trim((string) $this->option('symbol'))) : null;
        $type = strtolower(trim((string) $this->option('type') ?: 'all'));

        $allowedTypes = ['all', 'bars', 'financials', 'profile', 'quote', 'intraday', 'estimates', 'screener', 'earnings'];
        if (! in_array($type, $allowedTypes, true)) {
            $this->error("Invalid --type [{$type}]. Allowed types: " . implode(', ', $allowedTypes));
            return self::FAILURE;
        }

        // Administrative safeguards: require explicit authorization in both interactive and non-interactive environments
        if (! $this->option('force')) {
            if (! $this->input->isInteractive()) {
                $this->error('Permanent deletion in non-interactive environments requires explicit authorization with the --force flag.');
                return self::FAILURE;
            }

            if (! $symbol) {
                $confirm = $this->ask("WARNING: This will PERMANENTLY delete market data records ({$type}) for ALL symbols from the database. Type 'PURGE' to confirm");

                if ($confirm !== 'PURGE') {
                    $this->warn('Permanent deletion cancelled. Confirmation string did not match.');
                    return self::SUCCESS;
                }
            } else {
                if (! $this->confirm("Are you sure you want to PERMANENTLY delete market data records ({$type}) for [{$symbol}] from the database?")) {
                    $this->info('Permanent deletion cancelled.');
                    return self::SUCCESS;
                }
            }
        }

        $deleted = [
            'bars' => 0,
            'profiles' => 0,
            'financials' => 0,
            'payloads' => 0,
        ];

        // 1. Purge bars
        if (in_array($type, ['all', 'bars'], true)) {
            $barsQuery = StockDailyBar::query();
            if ($symbol) {
                $barsQuery->where('symbol', $symbol);
            }
            $deleted['bars'] = $barsQuery->delete();

            $payloadBarsQuery = MarketDataPayload::query()->where('data_type', 'bars');
            if ($symbol) {
                $payloadBarsQuery->where('symbol', $symbol);
            }
            $deleted['payloads'] += $payloadBarsQuery->delete();
        }

        // 2. Purge profiles
        if (in_array($type, ['all', 'profile'], true)) {
            $profilesQuery = CompanyProfile::query();
            if ($symbol) {
                $profilesQuery->where('symbol', $symbol);
            }
            $deleted['profiles'] = $profilesQuery->delete();

            $payloadProfileQuery = MarketDataPayload::query()->where('data_type', 'profile');
            if ($symbol) {
                $payloadProfileQuery->where('symbol', $symbol);
            }
            $deleted['payloads'] += $payloadProfileQuery->delete();
        }

        // 3. Purge financials
        if (in_array($type, ['all', 'financials'], true)) {
            $financialsQuery = CompanyFinancial::query();
            if ($symbol) {
                $financialsQuery->where('symbol', $symbol);
            }
            $deleted['financials'] = $financialsQuery->delete();

            $payloadFinQuery = MarketDataPayload::query()->whereIn('data_type', [
                'income', 'balance_sheet', 'cash_flow',
                'refresh_income', 'refresh_balance_sheet', 'refresh_cash_flow',
            ]);
            if ($symbol) {
                $payloadFinQuery->where('symbol', $symbol);
            }
            $deleted['payloads'] += $payloadFinQuery->delete();
        }

        // 4. Purge quotes
        if (in_array($type, ['all', 'quote'], true)) {
            $payloadQuoteQuery = MarketDataPayload::query()->where('data_type', 'quote');
            if ($symbol) {
                $payloadQuoteQuery->where('symbol', $symbol);
            }
            $deleted['payloads'] += $payloadQuoteQuery->delete();
        }

        // 5. Purge intraday
        if (in_array($type, ['all', 'intraday'], true)) {
            $payloadIntraQuery = MarketDataPayload::query()->where('data_type', 'like', 'intraday_%');
            if ($symbol) {
                $payloadIntraQuery->where('symbol', $symbol);
            }
            $deleted['payloads'] += $payloadIntraQuery->delete();
        }

        // 6. Purge analyst estimates
        if (in_array($type, ['all', 'estimates'], true)) {
            $payloadEstQuery = MarketDataPayload::query()->where('data_type', 'analyst_estimates');
            if ($symbol) {
                $payloadEstQuery->where('symbol', $symbol);
            }
            $deleted['payloads'] += $payloadEstQuery->delete();
        }

        // 7. Purge screener (shared across all companies; never purge when scoping to a specific symbol)
        if (in_array($type, ['all', 'screener'], true)) {
            if (! $symbol) {
                $deleted['payloads'] += MarketDataPayload::query()->where('data_type', 'screener')->delete();
            }
        }

        // 8. Purge earnings (calendar/surprises are shared across all companies; never purge when scoping to a specific symbol)
        if (in_array($type, ['all', 'earnings'], true)) {
            if (! $symbol) {
                $deleted['payloads'] += MarketDataPayload::query()->whereIn('data_type', ['earnings_calendar', 'earnings_surprises'])->delete();
            }
        }

        // Invalidate memory cache version
        PersistentMarketDataProvider::bumpCacheVersion($type, $symbol ?? '');

        Log::warning('market_data.database_purged', [
            'symbol' => $symbol ?? 'ALL',
            'type' => $type,
            'deleted' => $deleted,
        ]);

        $this->warn('Market data records permanently deleted from the database.');
        $this->table(
            ['Data Category', 'Records Purged'],
            [
                ['Stock Daily Bars', $deleted['bars']],
                ['Company Profiles', $deleted['profiles']],
                ['Company Financials', $deleted['financials']],
                ['Raw Payload Archives', $deleted['payloads']],
            ]
        );

        return self::SUCCESS;
    }
}
