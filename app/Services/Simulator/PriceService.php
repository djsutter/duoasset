<?php

namespace App\Services\Simulator;

// -----------------------------------------------------------------------------
// Simple price provider (stub) — adapt to your existing price service
// -----------------------------------------------------------------------------
use Carbon\Carbon;

class PriceService
{
    protected array $cache = [];

    // For demo we simulate price via simple random walk or you can inject real prices
    public function priceAt(string $asset, Carbon $when): float
    {
        $key = $asset.'|'.$when->format('Y-m-d H:00');
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        // deterministic-ish pseudo-random but repeatable: use date hash
        $seed = intval($when->format('U')) % 100000;
        $base = match ($asset) {
            'BTC' => 40000.0,
            'ETH' => 2500.0,
            default => 1.0,
        };

        // simple seasonal/random variation
        $variation = (sin($seed / 10000) + (crc32($key) % 1000) / 1000 - 0.5) * 0.2;
        $price = max(0.01, $base * (1 + $variation));

        return $this->cache[$key] = round($price, 2);
    }
}
