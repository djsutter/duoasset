<?php

namespace App\Services\Simulator;

// -----------------------------------------------------------------------------
// Swing trade generator — buys/sells based on synthetic signals
// -----------------------------------------------------------------------------
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SwingGenerator implements GeneratorInterface
{
    protected PriceService $priceService;

    protected string $asset;

    protected string $walletId;

    protected float $tradeSizeCad;

    public function __construct(PriceService $priceService, string $asset = 'BTC', float $tradeSizeCad = 1000.0, string $walletId = 'wallet:2')
    {
        $this->priceService = $priceService;
        $this->asset = $asset;
        $this->walletId = $walletId;
        $this->tradeSizeCad = $tradeSizeCad;
    }

    public function generate(Carbon $start, Carbon $end, array $context = []): Collection
    {
        $events = collect();
        $cursor = $start->copy();

        // Walk by days and generate occasional swing trades
        while ($cursor->lte($end)) {
            // probability increases during "bull" phases, but here use random
            if (rand(1, 100) <= 5) { // ~5% chance per day to trade
                $price = $this->priceService->priceAt($this->asset, $cursor);
                $buyOrSell = rand(0, 1) ? 'buy' : 'sell';
                $amount = $this->tradeSizeCad / $price;

                $tx = new SyntheticTransaction([
                    'timestamp' => $cursor->copy()->hour(rand(10, 18))->minute(rand(0, 59)),
                    'type' => $buyOrSell,
                    'asset' => $this->asset,
                    'amount' => round($amount, 8),
                    'price_cad' => $price,
                    'fiat' => round($this->tradeSizeCad, 2),
                    'from' => $buyOrSell === 'buy' ? 'exchange:cex-a' : $this->walletId,
                    'to' => $buyOrSell === 'buy' ? $this->walletId : 'exchange:cex-a',
                    'meta' => ['strategy' => 'swing'],
                ]);

                $events->push($tx);
            }

            $cursor->addDay();
        }

        return $events;
    }
}
