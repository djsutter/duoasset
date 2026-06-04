<?php

namespace App\Console\Commands;

use Carbon\Carbon;

class RawTrade
{
    public function __construct(
        public string $txid,
        public string $ordertxid,
        public Carbon $date,
        public string $type,
        public string $base,   // BTC
        public string $quote,  // CAD
        public string $price,
        public string $volume,
        public string $cost,
        public ?string $fee = null,
        public ?string $feeCurrency = null,
    ) {}
}
