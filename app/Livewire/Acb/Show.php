<?php

namespace App\Livewire\Acb;

use App\Models\Asset;
use Livewire\Component;

class Show extends Component
{
    public Asset $asset;

    public string $detail = 'daily';

    public function render()
    {
        return view('livewire.acb.show');
    }
}
