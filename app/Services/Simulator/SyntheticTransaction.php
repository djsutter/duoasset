<?php

// DuoAsset Crypto Simulator — Laravel framework
// Files included (single-file layout for clarity). Copy into app/Services/Simulator/ and app/Console/Commands as needed.

namespace App\Services\Simulator;

use Carbon\Carbon;

// -----------------------------------------------------------------------------
// SyntheticTransaction data object
// -----------------------------------------------------------------------------
class SyntheticTransaction
{
    public string $id;

    public Carbon $timestamp;

    public string $type; // buy, sell, transfer, fee, reward

    public string $asset; // e.g. BTC

    public float $amount; // asset amount

    public ?float $price_cad; // price in CAD at time (nullable)

    public ?float $fiat; // fiat amount (CAD)

    public ?string $from; // wallet/exchange id

    public ?string $to; // wallet/exchange id

    public array $meta = [];

    public function __construct(array $attrs = [])
    {
        $this->id = $attrs['id'] ?? uniqid('tx_');
        $this->timestamp = $attrs['timestamp'] ?? Carbon::now();
        $this->type = $attrs['type'] ?? 'unknown';
        $this->asset = $attrs['asset'] ?? 'BTC';
        $this->amount = $attrs['amount'] ?? 0.0;
        $this->price_cad = $attrs['price_cad'] ?? null;
        $this->fiat = $attrs['fiat'] ?? null;
        $this->from = $attrs['from'] ?? null;
        $this->to = $attrs['to'] ?? null;
        $this->meta = $attrs['meta'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'timestamp' => $this->timestamp->toDateTimeString(),
            'type' => $this->type,
            'asset' => $this->asset,
            'amount' => $this->amount,
            'price_cad' => $this->price_cad,
            'fiat' => $this->fiat,
            'from' => $this->from,
            'to' => $this->to,
            'meta' => $this->meta,
        ];
    }
}
