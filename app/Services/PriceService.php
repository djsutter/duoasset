<?php

namespace App\Services;

use App\Models\PriceHistory;
use Carbon\Carbon;

class PriceService
{
    protected $httpClient;

    public function __construct()
    {
        $this->httpClient = new \GuzzleHttp\Client;
    }

    /**
     * Returns 1 unit of foreign currency expressed in reporting currency.
     */
    public function getCadPrice(string $currency, Carbon|string $date): ?string
    {
        $date = carbonize($date);
        $price = PriceHistory::where('currency', $currency)->where('date', $date->toDateString())->first();

        if (! $price) {
            \Log::info("price $currency $date not found");

            return null;
        }

        return $price->cad;
    }
}
