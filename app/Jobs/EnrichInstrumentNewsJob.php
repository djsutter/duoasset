<?php

namespace App\Jobs;

use App\Services\Stocks\InstrumentClassifier;
use App\Services\Stocks\OllamaNewsEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnrichInstrumentNewsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 35;

    public int $uniqueFor = 60;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public string $symbol,
        public array $items = [],
    ) {
        $this->symbol = strtoupper(trim($symbol));
    }

    public function uniqueId(): string
    {
        return 'enrich_news_'.$this->symbol;
    }

    public function handle(InstrumentClassifier $classifier, OllamaNewsEnricher $enricher): void
    {
        if (! $enricher->isEnabled() || empty($this->items)) {
            return;
        }

        $instrument = $classifier->resolve($this->symbol);
        $enricher->enrichBatch($instrument, $this->items);
    }
}
