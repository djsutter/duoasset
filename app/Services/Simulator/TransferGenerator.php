<?php

namespace App\Services\Simulator;

// -----------------------------------------------------------------------------
// Transfer generator — simulates movements between wallets/exchanges
// -----------------------------------------------------------------------------
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TransferGenerator implements GeneratorInterface
{
    protected string $asset;

    protected array $wallets;

    protected int $monthlyTransfers;

    public function __construct(string $asset = 'BTC', array $wallets = ['wallet:1', 'wallet:2', 'exchange:cex-a'], int $monthlyTransfers = 3)
    {
        $this->asset = $asset;
        $this->wallets = $wallets;
        $this->monthlyTransfers = $monthlyTransfers;
    }

    public function generate(Carbon $start, Carbon $end, array $context = []): Collection
    {
        $events = collect();
        $cursor = $start->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            for ($i = 0; $i < $this->monthlyTransfers; $i++) {
                $day = rand(1, $cursor->daysInMonth);
                $timestamp = $cursor->copy()->day($day)->hour(rand(8, 20))->minute(rand(0, 59));

                $from = $this->wallets[array_rand($this->wallets)];
                do {
                    $to = $this->wallets[array_rand($this->wallets)];
                } while ($to === $from);

                $amount = rand(1, 100) / 1000; // small random amount

                $tx = new SyntheticTransaction([
                    'timestamp' => $timestamp,
                    'type' => 'transfer',
                    'asset' => $this->asset,
                    'amount' => round($amount, 8),
                    'from' => $from,
                    'to' => $to,
                    'meta' => ['strategy' => 'transfer'],
                ]);

                $events->push($tx);
            }

            $cursor->addMonth();
        }

        return $events;
    }
}
