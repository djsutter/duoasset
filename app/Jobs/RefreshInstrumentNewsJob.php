<?php

namespace App\Jobs;

use App\Services\Stocks\InstrumentNewsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshInstrumentNewsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public int $uniqueFor = 45;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $symbol,
        public array $options = [],
    ) {
        $this->symbol = strtoupper(trim($symbol));
    }

    public function uniqueId(): string
    {
        $from = (string) ($this->options['from'] ?? 'default');
        $to = (string) ($this->options['to'] ?? 'default');

        return 'refresh_news_'.$this->symbol.'_'.md5($from.'|'.$to);
    }

    public function handle(InstrumentNewsService $newsService): void
    {
        $opts = $this->options;
        $opts['refresh'] = true;
        $opts['async'] = false;

        $newsService->getNews($this->symbol, $opts);
    }
}
