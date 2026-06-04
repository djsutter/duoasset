<?php

namespace App\Livewire\Acb;

use App\Models\AcbDaily;
use App\Models\AcbEvent;
use App\Models\Asset;
use App\Services\Reports\LedgerCapitalGainsReportService;
use Livewire\Component;

class Detail extends Component
{
    public Asset $asset;

    public string $detail = 'daily';

    public function render()
    {
        $data = [];

        if ($this->detail == 'daily') {
            $data = AcbDaily::where('asset_code', $this->asset->asset_code)
                ->orderBy('date')
                ->get();
        } elseif ($this->detail == 'events') {
            $data = AcbEvent::where('asset_code', $this->asset->asset_code)
                ->orderBy('event_at')
                ->get();
        } elseif ($this->detail == 'disposals') {
            $service = app(LedgerCapitalGainsReportService::class);
            $service->generateLedgerReport(2020, $this->asset->asset_code);
            $data = $service->ledgerRows();
        }

        return view('livewire.acb.detail.'.$this->detail, compact('data'));
    }
}
