<?php

namespace App\Livewire\Platforms;

use App\Models\Platform;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        return view('livewire.platforms.index', [
            'platforms' => Platform::orderBy('name')->get(),
        ]);
    }
}
