<?php

namespace App\Console\Commands;

use App\Models\CompanyFinancial;
use App\Models\CompanyProfile;
use App\Models\MarketDataPayload;
use App\Models\StockDailyBar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClearMarketDataCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market-data:clear
                            {--symbol= : Specific ticker symbol (e.g. AAPL)}
                            {--type=all : Data type to clear (all, bars, financials, profile)}
                            {--force : Force the operation without interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wipe previously cached market data (bars, profile, financials) from database and memory tiers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $symbol = $this->option('symbol') ? strtoupper(trim((string) $this->option('symbol'))) : null;
        $type = strtolower(trim((string) $this->option('type') ?: 'all'));

        $allowedTypes = ['all', 'bars', 'financials', 'profile'];
        if (! in_array($type, $allowedTypes, true)) {
            $this->error("Invalid --type [{$type}]. Allowed types: " . implode(', ', $allowedTypes));
            return self::FAILURE;
        }

        // If wiping globally across all symbols, confirm unless --force
        if (! $symbol && ! $this->option('force')) {
            if ($this->input->isInteractive() && ! $this->confirm("Are you sure you want to wipe cached market data ({$type}) for ALL symbols?")) {
                $this->info('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        $deleted = [
            'bars' => 0,
            'profiles' => 0,
            'financials' => 0,
            'payloads' => 0,
        ];

        // 1. Clear bars
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

        // 2. Clear profiles
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

        // 3. Clear financials
        if (in_array($type, ['all', 'financials'], true)) {
            $financialsQuery = CompanyFinancial::query();
            if ($symbol) {
                $financialsQuery->where('symbol', $symbol);
            }
            $deleted['financials'] = $financialsQuery->delete();

            $payloadFinQuery = MarketDataPayload::query()->whereIn('data_type', ['income', 'balance_sheet', 'cash_flow']);
            if ($symbol) {
                $payloadFinQuery->where('symbol', $symbol);
            }
            $deleted['payloads'] += $payloadFinQuery->delete();
        }

        // Flush memory cache tier for the symbol or globally
        if ($symbol) {
            Cache::forget("duoasset.profile.{$symbol}");
            Cache::forget("fmp.quote.{$symbol}");
            Cache::forget("fmp.profile_raw.{$symbol}");
        } else {
            Cache::flush();
        }

        Log::info('market_data.cache_cleared', [
            'symbol' => $symbol ?? 'ALL',
            'type' => $type,
            'deleted' => $deleted,
        ]);

        $this->info('Market data cache successfully cleared.');
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
