<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketDataProvider;
use App\Services\Stocks\InstrumentClassifier;
use App\Services\Stocks\InstrumentNewsService;
use App\Services\Stocks\OllamaNewsEnricher;
use Illuminate\Console\Command;

class VerifyInstrumentNewsCommand extends Command
{
    protected $signature = 'news:verify
        {symbol=NBIS : Stock or futures symbol to inspect (e.g. NBIS, MASS, CRON, GCUSD)}
        {--fresh : Bypass cache and fetch fresh news from configured sources}
        {--limit=10 : Number of retained articles to display}
        {--searxng : Force a SearXNG discovery query alongside primary sources}
        {--ai : Run synchronous Ollama enrichment on the retrieved batch}';

    protected $description = 'Verify Interactive News Timeline provider chain, source connectivity, relevance filtering, and retained articles for a symbol.';

    public function handle(
        MarketDataProvider $provider,
        InstrumentClassifier $classifier,
        InstrumentNewsService $newsService,
        OllamaNewsEnricher $aiEnricher,
    ): int {
        $symbol = strtoupper(trim((string) $this->argument('symbol')));
        $limit = max(1, min(50, (int) $this->option('limit')));

        $this->info("=== DuoAsset Interactive News Timeline Verification: {$symbol} ===");

        $providerClass = get_class($provider);
        $upstreamClass = method_exists($provider, 'getUpstream') ? get_class($provider->getUpstream()) : 'none';
        $this->line("Bound MarketDataProvider : {$providerClass}");
        $this->line("Upstream Provider        : {$upstreamClass}");
        $this->line('Timeline Enabled         : '.(config('market_data.news.enabled', true) ? 'yes' : 'no'));
        $this->line('Cache Store / Queue      : '.config('cache.default').' / '.config('queue.default'));

        $requiredMethods = ['stockNews', 'forexNews', 'generalNews', 'pressReleases', 'economicCalendar'];
        $missingMethods = [];
        foreach ($requiredMethods as $m) {
            if (! method_exists($provider, $m)) {
                $missingMethods[] = $m;
            }
        }

        if (! empty($missingMethods)) {
            $this->error('Missing methods on bound MarketDataProvider: '.implode(', ', $missingMethods));

            return self::FAILURE;
        }
        $this->line('Provider News Methods    : OK ('.implode(', ', $requiredMethods).')');

        $instrument = $classifier->resolve($symbol);
        $this->line(sprintf(
            'Resolved Instrument      : %s (%s) | Type: %s | Exchange: %s | Unit: %s',
            $instrument['symbol'],
            $instrument['name'] ?? 'n/a',
            $instrument['asset_type'],
            $instrument['exchange'] ?? 'n/a',
            $instrument['price_unit'] ?? 'n/a'
        ));

        if ($this->option('ai')) {
            config([
                'market_data.news.ai_enrichment.enabled' => true,
                'market_data.news.ai_enrichment.async' => false,
            ]);
        }

        $result = $newsService->getNews($symbol, [
            'refresh' => (bool) $this->option('fresh'),
            'force_searxng' => (bool) $this->option('searxng'),
            'limit' => $limit,
        ]);

        $diag = $result['diagnostics'] ?? [];
        $this->newLine();
        $this->info('--- Retrieval Summary ---');
        $this->line('Status                   : '.($result['status'] ?? 'unknown'));
        if (! empty($result['status_message'])) {
            $this->line('Status Message           : '.$result['status_message']);
        }
        $this->line('Cache Hit                : '.(! empty($diag['cache_hit']) ? 'yes' : 'no'));
        $this->line('Raw Articles Collected   : '.($diag['raw_count'] ?? 0));
        $this->line('Retained Articles        : '.($diag['retained_count'] ?? $result['count'] ?? 0));
        $this->line('Filtered / Deduplicated  : '.($diag['filtered_count'] ?? 0));
        $this->line('Chart Markers Generated  : '.count($result['markers'] ?? []));
        $this->line('Duration                 : '.($diag['duration_ms'] ?? 0).' ms');

        $sourceRows = [];
        foreach ((array) ($diag['sources'] ?? []) as $src => $sStat) {
            $sourceRows[] = [
                $src,
                $sStat['status'] ?? 'unknown',
                $sStat['count'] ?? 0,
                isset($sStat['duration_ms']) ? $sStat['duration_ms'].' ms' : '—',
                $sStat['error'] ?? '—',
            ];
        }
        if (! empty($sourceRows)) {
            $this->newLine();
            $this->table(['Source', 'Status', 'Raw Count', 'Duration', 'Error'], $sourceRows);
        }

        $items = $result['items'] ?? [];
        if (! empty($items)) {
            $this->newLine();
            $this->info("--- Top Retained Articles ({$result['count']}) ---");
            $tableRows = [];
            foreach (array_slice($items, 0, $limit) as $item) {
                $tableRows[] = [
                    $item['published_display'] ?? '',
                    $item['relevance_score'] ?? 0,
                    $item['primary_category_label'] ?? '',
                    $item['source'] ?? '',
                    mb_substr((string) ($item['title'] ?? ''), 0, 72),
                ];
            }
            $this->table(['Published (UTC)', 'Score', 'Category', 'Publisher', 'Headline'], $tableRows);
        } else {
            $this->warn('No retained articles returned.');
        }

        return in_array($result['status'] ?? '', ['auth_error', 'service_unavailable'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
