<?php

namespace App\Data\Tax\Schedule3;

use App\Types\Money;
use Carbon\CarbonImmutable;

final readonly class Schedule3SuperficialLossData
{
    public function __construct(
        // Identity
        public string $acb_event_id,

        // Loss amounts
        public Money $capital_gain_loss_before_denial,
        public Money $denied_loss_amount,
        public Money $capital_gain_loss_after_denial,

        // Reasoning
        public string $denial_reason,          // human-readable
        public ?CarbonImmutable $window_start,
        public ?CarbonImmutable $window_end,

        // Resolution
        public ?string $resolution_type,        // added_to_acb | pending | expired
        public ?string $replacement_acb_event_id,
    ) {}
}
