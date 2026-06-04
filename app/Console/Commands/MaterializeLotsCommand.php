<?php

namespace App\Console\Commands;

use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use App\Models\Lot;
use Illuminate\Console\Command;

final class MaterializeLotsCommand extends Command
{
    protected $signature = 'acb:materialize-lots';

    public function handle(): int
    {
        $events = AcbEvent::query()
            ->where(fn ($q) => $q->whereIn(
                'event_type',
                collect(AcbEventType::cases())->filter->createsLot()
            ))
            ->orderBy('event_at')
            ->get();

        foreach ($events as $event) {
            Lot::firstOrCreate(
                ['acb_event_id' => $event->id],
                [
                    'asset_code' => $event->asset_code,
                    'acquired_at' => $event->event_at,
                    'original_quantity' => $event->quantity,
                    'remaining_quantity' => $event->quantity,
                    'original_acb_amount' => $event->cost_amount,
                ]
            );
        }

        return self::SUCCESS;
    }
}
