<?php

namespace App\Services\Simulator;

// -----------------------------------------------------------------------------
// DCA (Dollar Cost Averaging) Generator — buys a fixed amount on a schedule
// -----------------------------------------------------------------------------
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DcaGenerator implements GeneratorInterface
{
    protected PriceService $priceService;

    protected string $asset;

    protected float $cadPerPurchase;

    protected string $walletId;

    protected string $frequency; // daily, weekly, monthly

    public function __construct(PriceService $priceService, string $asset = 'BTC', float $cadPerPurchase = 100.0, string $walletId = 'wallet:1', string $frequency = 'weekly')
    {
        $this->priceService = $priceService;
        $this->asset = $asset;
        $this->cadPerPurchase = $cadPerPurchase;
        $this->walletId = $walletId;
        $this->frequency = $frequency;
    }

    public function generate(Carbon $start, Carbon $end, array $context = []): Collection
    {
        $events = collect();
        $cursor = $start->copy();

        $step = match ($this->frequency) {
            'daily' => '1 day',
            'weekly' => '1 week',
            'monthly' => '1 month',
            default => '1 week',
        };

        while ($cursor->lte($end)) {
            $price = $this->priceService->priceAt($this->asset, $cursor);
            $amount = $this->cadPerPurchase / $price;

            $tx = new SyntheticTransaction([
                'timestamp' => $cursor->copy()->hour(rand(9, 17))->minute(rand(0, 59)),
                'type' => 'buy',
                'asset' => $this->asset,
                'amount' => round($amount, 8),
                'price_cad' => $price,
                'fiat' => round($this->cadPerPurchase, 2),
                'from' => 'fiat:bank',
                'to' => $this->walletId,
                'meta' => ['strategy' => 'dca', 'frequency' => $this->frequency],
            ]);

            $events->push($tx);
            $cursor->add($step);
        }

        return $events;
    }
}
