<?php

namespace App\Data\Imports;

class KuCoinEventData
{
    public function __construct(
        public readonly string $time,
        public readonly string $event_type,
        public readonly string $side,
        public readonly string $currency,
        public readonly string $amount,
        public readonly ?string $fee,
        public readonly string $account_type,
    ) {}
}
