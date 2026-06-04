<?php

namespace App\Livewire\Tax;

use App\Data\Tax\Schedule3\Schedule3Data;
use App\Data\Tax\Schedule3\Schedule3DispositionData;
use App\Data\Tax\Schedule3\Schedule3LotAllocationData;
use App\Data\Tax\Schedule3\Schedule3SuperficialLossData;
use App\Models\AcbEvent;
use App\Services\Tax\TaxService;
use Livewire\Component;

final class Schedule3DispositionShow extends Component
{
    public int $year;

    public string $asset;

    public int $acbEventId; // match the integer type of acb_events.id

    private TaxService $taxService;

    public function boot(TaxService $taxService): void
    {
        $this->taxService = $taxService;
    }

    public function mount(int $year, string $asset, AcbEvent $acbEvent): void
    {
        $this->year = $year;
        $this->asset = $asset;
        $this->acbEventId = $acbEvent->id;
    }

    public function getSchedule3Property(): Schedule3Data
    {
        return $this->taxService->buildSchedule3($this->year);
    }

    public function getDispositionsProperty(): array
    {
        $schedule3 = $this->schedule3;

        foreach ($schedule3->asset_rows as $assetRow) {
            if ($assetRow->asset_code === $this->asset) {
                return $assetRow->dispositions;
            }
        }

        // If not found, return empty array
        return [];
    }

    public function getDispositionProperty(): ?Schedule3DispositionData
    {
        foreach ($this->dispositions as $disposition) {
            if ($disposition->acb_event_id === $this->acbEventId) {
                return $disposition;
            }
        }

        return null;
    }

    /** @return Schedule3LotAllocationData[] */
    public function getLotBreakdownProperty(): array
    {
        return $this->disposition?->lot_allocations ?? [];
    }

    public function getSuperficialLossProperty(): ?Schedule3SuperficialLossData
    {
        return $this->disposition?->superficial_loss;
    }

    public function render()
    {
        return view('livewire.tax.schedule3-disposition-show');
    }
}
