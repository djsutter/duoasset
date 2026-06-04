<?php

namespace App\Livewire\Wallets;

use App\Models\Platform;
use App\Models\Wallet;
use Livewire\Component;

class Index extends Component
{
    public function render()
    {
        $exchanges = Platform::where('type', 'exchange')->with('wallets')->get();
        $softwareWallets = Platform::where('type', 'software')->with('wallets')->get();
        $externalWallets = [
            [
                'name' => 'External Wallets',
                'wallets' => Wallet::where('type', 'external')->get(),
            ],
        ];

        return view('livewire.wallets.index', compact('exchanges', 'softwareWallets', 'externalWallets'));
    }
}
