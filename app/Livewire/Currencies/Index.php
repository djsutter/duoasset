<?php

namespace App\Livewire\Currencies;

use App\Models\Currency;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('livewire.currencies.index', [
            'currencies' => Currency::all(),
        ]);
    }
}
