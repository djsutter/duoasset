<?php

namespace App\Tax\Events;

use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class EloquentAcquisitionEventRepository implements AcquisitionEventRepository
{
    public function recent(): Collection
    {
        return AcbEvent::query()
            ->where('event_type', AcbEventType::Acquisition)
            ->orderBy('event_at')
            ->get()
            ->map(fn (AcbEvent $event) => new AcquisitionEvent(
                id: $event->id,
                assetCode: $event->asset_code,
                date: CarbonImmutable::parse($event->event_at),
                quantity: $event->quantity,
                costAmount: $event->cost_amount,
            ));
    }
}
