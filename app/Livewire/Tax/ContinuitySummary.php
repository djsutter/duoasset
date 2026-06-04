<?php

namespace App\Livewire\Tax;

use App\Domain\Tax\Continuity\TaxYearContinuityService;
use App\Enums\AcbEventType;
use App\Models\AcbEvent;
use App\Models\TaxPool;
use Livewire\Component;

class ContinuitySummary extends Component
{
    public string $assetCode = '';

    public $assets = [];

    public int $year;

    public function mount(?int $taxYear = null)
    {
        $this->assets = TaxPool::orderBy('asset_code')->pluck('asset_code')->toArray();
        $this->year = $this->yearOptions[0] ?? now()->year;
    }

    public function getYearOptionsProperty(): array
    {
        $years = AcbEvent::where('event_type', AcbEventType::Disposal)
            ->selectRaw('MIN(YEAR(event_at)) as min_year, MAX(YEAR(event_at)) as max_year')
            ->first();

        if (! $years || ! $years->min_year) {
            return [now()->year];
        }

        return range($years->max_year, $years->min_year);
    }

    public function render()
    {
        $service = app(TaxYearContinuityService::class);

        $report = $service->buildForAssetAndTaxYear($this->assetCode, $this->year);

        return view('livewire.tax.continuity-summary', [
            'report' => $report,
        ]);
    }
}
