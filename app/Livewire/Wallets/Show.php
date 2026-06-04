<?php

namespace App\Livewire\Wallets;

use App\Models\Wallet;
use App\Services\WalletService;
use Livewire\Component;

class Show extends Component
{
    public Wallet $wallet;

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    public function editTransaction(int $transactionId)
    {
        return redirect(route('transactions.edit', $transactionId));
    }

    public function render()
    {
        $walletService = app(WalletService::class);

        return view('livewire.wallets.show', [
            'platform' => $this->wallet->platform?->name,
            'transactions' => $walletService->getTransactions($this->wallet),
        ]);
    }
}
