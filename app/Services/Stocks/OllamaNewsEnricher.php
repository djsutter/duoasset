<?php

namespace App\Services\Stocks;

use App\Models\InstrumentNewsArticle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OllamaNewsEnricher
{
    public function isEnabled(): bool
    {
        return (bool) config('market_data.news.ai_enrichment.enabled', false);
    }

    public function isAsync(): bool
    {
        return (bool) config('market_data.news.ai_enrichment.async', true);
    }

    /**
     * Attach already-cached AI enrichment fields to normalized news items without waiting on Ollama.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    public function attachCachedEnrichments(string $symbol, array $items): array
    {
        $symbol = strtoupper(trim($symbol));
        $unEnriched = [];

        foreach ($items as $idx => $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $enrichment = $item['ai_enrichment'] ?? null;
            if (! is_array($enrichment)) {
                $cached = Cache::get($this->cacheKey($symbol, $id));
                if (is_array($cached)) {
                    $enrichment = $cached;
                }
            }

            if (is_array($enrichment) && isset($enrichment['relevant'])) {
                $items[$idx]['ai_enriched'] = true;
                $items[$idx]['ai_relevant'] = (bool) ($enrichment['relevant'] ?? true);
                $items[$idx]['ai_market_moving'] = (bool) ($enrichment['market_moving'] ?? false);
                $items[$idx]['ai_rationale'] = isset($enrichment['why_it_matters']) && trim((string) $enrichment['why_it_matters']) !== ''
                    ? trim((string) $enrichment['why_it_matters'])
                    : null;
                $items[$idx]['ai_model'] = (string) ($enrichment['model'] ?? '');
            } else {
                $items[$idx]['ai_enriched'] = false;
                $unEnriched[] = $item;
            }
        }

        return [$items, $unEnriched];
    }

    /**
     * Enrich a bounded batch of news items via the configured local Ollama instance.
     * Never fabricates articles, timestamps, or URLs. Safe to call in background jobs or synchronously.
     *
     * @param  array<string, mixed>  $instrument
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    public function enrichBatch(array $instrument, array $items): array
    {
        if (! $this->isEnabled() || empty($items)) {
            return [];
        }

        $symbol = strtoupper((string) ($instrument['symbol'] ?? ''));
        if ($symbol === '') {
            return [];
        }

        $maxBatch = max(1, min(20, (int) config('market_data.news.ai_enrichment.max_batch_size', 8)));
        $batch = array_slice(array_values($items), 0, $maxBatch);

        $lockKey = "lock.duoasset.news.ai.{$symbol}";
        $lock = Cache::lock($lockKey, 30);
        if (! $lock->get()) {
            return [];
        }

        $t0 = microtime(true);
        $baseUrl = rtrim((string) config('market_data.news.ai_enrichment.ollama_url', 'http://127.0.0.1:11434'), '/');
        $model = (string) config('market_data.news.ai_enrichment.model', 'qwen3:8b');
        $timeout = max(3, min(60, (int) config('market_data.news.ai_enrichment.timeout_seconds', 15)));
        $ttlDays = max(1, (int) config('market_data.news.ai_enrichment.cache_ttl_days', 30));

        try {
            $payloadItems = [];
            foreach ($batch as $idx => $item) {
                $payloadItems[] = [
                    'idx' => $idx,
                    'id' => (string) ($item['id'] ?? ''),
                    'headline' => (string) ($item['title'] ?? ''),
                    'summary' => mb_substr((string) ($item['summary'] ?? ''), 0, 220),
                ];
            }

            $instDesc = sprintf(
                '%s (%s, Exchange: %s, Type: %s%s)',
                $symbol,
                (string) ($instrument['name'] ?? $symbol),
                (string) ($instrument['exchange'] ?? 'Unknown'),
                (string) ($instrument['asset_type'] ?? 'stock'),
                ! empty($instrument['industry']) ? ', Industry: '.$instrument['industry'] : ''
            );

            $prompt = "Evaluate each financial news item for target instrument: {$instDesc}. "
                ."Reject ticker/name collisions (e.g. a different company on another exchange or an unrelated project). "
                ."Do not invent facts. Return JSON object: "
                ."{\"results\": [{\"idx\": 0, \"relevant\": true, \"market_moving\": false, \"why_it_matters\": \"Concise factual explanation under 18 words\"}]}\n"
                ."Input:\n".json_encode($payloadItems, JSON_UNESCAPED_SLASHES);

            $resp = Http::timeout($timeout)->post("{$baseUrl}/api/generate", [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'think' => false,
                'format' => 'json',
                'options' => [
                    'temperature' => 0.0,
                    'num_predict' => 600,
                ],
            ]);

            $durationMs = (int) round((microtime(true) - $t0) * 1000);

            if (! $resp->successful()) {
                Log::warning('instrument_news.ollama_unavailable', [
                    'symbol' => $symbol,
                    'model' => $model,
                    'status' => $resp->status(),
                    'duration_ms' => $durationMs,
                ]);

                return [];
            }

            $outer = $resp->json();
            $rawResponse = is_array($outer) ? (string) ($outer['response'] ?? '') : '';
            $decoded = json_decode($rawResponse, true);
            $results = is_array($decoded['results'] ?? null) ? $decoded['results'] : (is_array($decoded) ? $decoded : []);

            $enrichedById = [];
            $canPersistDb = (bool) config('market_data.news.persistent_storage_enabled', true)
                && Schema::hasTable('instrument_news_articles');

            foreach ($results as $row) {
                if (! is_array($row) || ! isset($row['idx'])) {
                    continue;
                }
                $idx = (int) $row['idx'];
                if (! isset($batch[$idx])) {
                    continue;
                }

                $itemId = (string) ($batch[$idx]['id'] ?? '');
                if ($itemId === '') {
                    continue;
                }

                $enrichment = [
                    'relevant' => (bool) ($row['relevant'] ?? true),
                    'market_moving' => (bool) ($row['market_moving'] ?? false),
                    'why_it_matters' => mb_substr(trim(strip_tags((string) ($row['why_it_matters'] ?? ''))), 0, 200),
                    'model' => $model,
                    'enriched_at' => now()->toIso8601String(),
                ];

                $enrichedById[$itemId] = $enrichment;
                Cache::put($this->cacheKey($symbol, $itemId), $enrichment, now()->addDays($ttlDays));

                if ($canPersistDb) {
                    try {
                        InstrumentNewsArticle::where('symbol', $symbol)
                            ->where('article_uid', $itemId)
                            ->update(['ai_enrichment' => $enrichment]);
                    } catch (\Throwable) {
                        // Ignore DB update error
                    }
                }
            }

            Log::info('instrument_news.ai_enrichment', [
                'symbol' => $symbol,
                'model' => $model,
                'batch_size' => count($batch),
                'enriched_count' => count($enrichedById),
                'duration_ms' => $durationMs,
            ]);

            return $enrichedById;
        } catch (\Throwable $e) {
            Log::warning('instrument_news.ollama_unavailable', [
                'symbol' => $symbol,
                'model' => $model,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            ]);

            return [];
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    public function cacheKey(string $symbol, string $articleId): string
    {
        return 'duoasset.news.ai.v1.'.strtoupper(trim($symbol)).'.'.$articleId;
    }
}
