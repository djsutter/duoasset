<?php

namespace App\Console\Commands;

use App\Services\MarketData\PersistentMarketDataProvider;
use Illuminate\Console\Command;
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
                            {--type=all : Memory cache type to clear (all, bars, financials, profile, quote, intraday, estimates, screener, earnings)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Invalidate market data memory caches without deleting permanent database records';

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

        // Invalidate memory cache version specifically for the requested type (and symbol)
        // This performs genuinely granular memory cache invalidation without touching unrelated caches
        // and NEVER deletes any permanent database records.
        PersistentMarketDataProvider::bumpCacheVersion($type, $symbol ?? '');

        Log::info('market_data.memory_cache_cleared', [
            'symbol' => $symbol ?? 'ALL',
            'type' => $type,
        ]);

        $target = $symbol ?: 'all symbols';
        $typeSuffix = $type !== 'all' ? " ({$type})" : '';
        $this->info("Market data memory cache{$typeSuffix} successfully cleared for {$target}.");
        $this->comment('Permanent database records were preserved.');

        return self::SUCCESS;
    }
}
