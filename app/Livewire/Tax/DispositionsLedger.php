<?php

namespace App\Livewire\Tax;

use App\Models\TaxPool;
use Livewire\Component;

class DispositionsLedger extends Component
{
    public $selectedAsset = null;

    public $assets = [];

    public function mount()
    {
        $this->assets = TaxPool::orderBy('asset_code')->pluck('asset_code')->toArray();
    }

    public function render()
    {
        $query = TaxPool::with(['dispositions' => fn ($q) => $q->orderBy('disposition_date')]);

        if ($this->selectedAsset) {
            $query->where('asset_code', $this->selectedAsset);
        }

        $taxPools = $query->get();

        return view('livewire.tax.dispositions-ledger', [
            'taxPools' => $taxPools,
        ]);
    }
}
