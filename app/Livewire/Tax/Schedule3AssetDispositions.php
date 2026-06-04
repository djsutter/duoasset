<?php

namespace App\Livewire\Tax;

use App\Data\Tax\Schedule3\Schedule3Data;
use App\Services\Tax\TaxService;
use App\Types\AssetQuantity;
use App\Types\Money;
use Livewire\Component;

class Schedule3AssetDispositions extends Component
{
    public int $year;

    public string $asset;

    private TaxService $taxService;

    public function boot(TaxService $taxService): void
    {
        $this->taxService = $taxService;
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

    public function getTotalsProperty(): array
    {
        $quantity = AssetQuantity::zero($this->asset);
        $proceeds = Money::zero('CAD');
        $acb = Money::zero('CAD');
        $gains = Money::zero('CAD');
        $denied_losses = Money::zero('CAD');

        /** @var \App\Data\Tax\Schedule3\Schedule3DispositionData $row */
        foreach ($this->dispositions as $row) {
            $quantity = $quantity->add($row->quantity);
            $proceeds = $proceeds->add($row->proceeds);
            $acb = $acb->add($row->acb_reportable);
            $gains = $gains->add($row->capital_gain_loss);
            $denied_losses = $denied_losses->add($row->denied_loss);
        }

        return compact('quantity', 'proceeds', 'acb', 'gains', 'denied_losses');
    }

    public function render()
    {
        return view('livewire.tax.schedule3-asset-dispositions');
    }
}
