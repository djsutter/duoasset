<?php

namespace App\Console\Commands;

use App\Models\EarningsAlert;
use App\Models\EarningsEvent;
use App\Services\MarketData\MarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class EarningsScannerHealth extends Command
{
    protected $signature = 'earnings:scanner-health';

    protected $description = 'Health check for the earnings surprise scanner.';

    public function handle(MarketDataProvider $provider): int
    {
        $ok = true;

        $hasKey = (bool) config('market_data.fmp.api_key');
        $this->line(($hasKey ? '<info>OK</info>  ' : '<error>FAIL</error>').' FMP_API_KEY present');
        $ok = $ok && $hasKey;

        $this->line('<info>OK</info>  Queue connection: '.config('queue.default'));

        try {
            $sample = $provider->earningsCalendar(
                CarbonImmutable::today()->subDay(),
                CarbonImmutable::today()->addDay(),
            );
            $this->line('<info>OK</info>  Provider reachable, calendar rows: '.count($sample));
        } catch (\Throwable $e) {
            $this->line('<error>FAIL</error> Provider unreachable: '.$e->getMessage());
            $ok = false;
        }

        $latest = EarningsEvent::query()->max('detected_at');
        $this->line('     Latest scan: '.($latest ?: 'never'));
        $this->line('     Events today: '.EarningsEvent::query()->whereDate('detected_at', today())->count());
        $this->line('     Alerts today: '.EarningsAlert::query()->whereDate('created_at', today())->count());

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
