<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\Alerts\AlertEvaluator;
use App\Services\MarketData\MarketDataProviderInterface;
use App\Services\MarketData\StockQuote;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MarketWatchUpdateQuotes extends Command
{
    protected $signature = 'market-watch:update-quotes
                            {--symbol=* : Limit to one or more symbols}
                            {--force : Bypass the per-stock cache}
                            {--cache-ttl=60 : Cache TTL in seconds for fetched quotes}';

    protected $description = 'Fetch the latest quotes for tracked stocks and evaluate watchlist alert rules.';

    public function handle(
        MarketDataProviderInterface $provider,
        AlertEvaluator $evaluator,
    ): int {
        $symbols = (array) $this->option('symbol');
        $ttl = max(0, (int) $this->option('cache-ttl'));
        $force = (bool) $this->option('force');

        $query = Stock::query();
        if (! empty($symbols)) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        }

        $stocks = $query->get();
        if ($stocks->isEmpty()) {
            $this->info('No stocks to update.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Updating %d stock(s) via [%s] provider…',
            $stocks->count(),
            $provider->name(),
        ));

        $updated = 0;
        $alerts = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($stocks as $stock) {
            $cacheKey = "market-watch:quote:{$provider->name()}:{$stock->id}";

            try {
                $quote = $force
                    ? $provider->fetchQuote($stock)
                    : Cache::remember($cacheKey, $ttl, fn () => $provider->fetchQuote($stock));
            } catch (Throwable $e) {
                $errors++;
                $this->error("[{$stock->symbol}] {$e->getMessage()}");

                continue;
            }

            if (! $quote instanceof StockQuote) {
                $skipped++;

                continue;
            }

            $this->applyQuote($stock, $quote);
            $updated++;

            $events = $evaluator->evaluateStock($stock->refresh());
            $alerts += count($events);
        }

        $this->info(sprintf(
            'Done. updated=%d skipped=%d errors=%d alerts=%d',
            $updated, $skipped, $errors, $alerts,
        ));

        return self::SUCCESS;
    }

    private function applyQuote(Stock $stock, StockQuote $quote): void
    {
        $stock->forceFill([
            'last_price' => $quote->lastPrice,
            'daily_change' => $quote->dailyChange,
            'daily_change_percent' => $quote->dailyChangePercent,
            'volume' => $quote->volume,
            'market_cap' => $quote->marketCap,
            'last_checked_at' => $quote->asOf?->toDateTimeImmutable() ?? now(),
        ])->save();
    }
}
