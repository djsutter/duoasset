<?php

namespace App\Queries\Tax\Schedule3;

use App\Data\Tax\Schedule3\Schedule3SuperficialLossData;
use App\Models\SuperficialLossEvent;
use Carbon\CarbonImmutable;

final class SuperficialLossQuery
{
    public static function forAcbEvent(int $acbEventId): ?Schedule3SuperficialLossData
    {
        $event = SuperficialLossEvent::query()
            ->where('acb_event_id', $acbEventId)
            ->first();

        if ($event === null) {
            return null;
        }

        return new Schedule3SuperficialLossData(
            acb_event_id: $event->acb_event_id,
            capital_gain_loss_before_denial: $event->capital_loss_before_denial,
            denied_loss_amount: $event->denied_loss_amount,
            allowable_loss_amount: $event->allowable_loss_amount,
            denial_reason: self::formatDenialReason($event),
            window_start: CarbonImmutable::parse($event->window_start),
            window_end: CarbonImmutable::parse($event->window_end),
            resolution_type: $event->resolution_type,
            replacement_acb_event_id: $event->replacement_acb_event_id,
        );
    }

    private static function formatDenialReason($row): string
    {
        // Keep this intentionally dumb and explicit.
        // This is presentation text, not business logic.

        return match ($row->reason_code) {
            'replacement_within_window' => 'Replacement property acquired within the 30-day superficial loss window',

            'affiliated_person_acquired' => 'Replacement property acquired by an affiliated person',

            default => 'Superficial loss rules applied',
        };
    }
}
