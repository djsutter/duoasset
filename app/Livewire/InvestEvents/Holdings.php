<?php

namespace App\Livewire\InvestEvents;

use App\Models\Invest\Account as InvestAccount;
use App\Models\Invest\Holding;
use App\Models\Wallet;
use App\Services\InvestAppService;
use App\Types\Money;
use Livewire\Component;

class Holdings extends Component
{
    public int $wallet_id;

    public int $invest_account_id;

    protected $listeners = [
        'refresh' => '$refresh',
    ];

    public function apply($date, $amount): void
    {
        if ($holding = Holding::where('date', $date)->where('account_id', $this->invest_account_id)->first()) {
            $holding->update(['amount' => str_replace(',', '', $amount)]);
        }
    }

    public function mount(): void
    {
        $this->wallet_id = 27;
    }

    public function render()
    {
        $service = app(InvestAppService::class);
        $wallet = Wallet::find($this->wallet_id);
        $currency = $wallet->currency;
        $platform = $wallet->platform;

        $accountMap = $service->mapAccounts();
        $investAccountName = null;
        foreach ($accountMap as $name => $detail) {
            if (
                $detail[0] == $platform->name && (
                    $detail[1] == $wallet->name ||
                    $detail[2] == $wallet->name)) {
                $investAccountName = $name;
            }
        }

        if ($investAccountName) {
            $investAccount = InvestAccount::where('name', $investAccountName)->first();
            $this->invest_account_id = $investAccount->id;
            $holdings = Holding::where('account_id', $investAccount->id)
                ->where('date', '>=', '2021-04-16')
                ->orderBy('date')
                ->get();
        } else {
            $holdings = collect();
        }

        foreach ($holdings as $holding) {
            $holding->balance = $wallet->getBalance($holding->date);
            $amount = Money::fromDecimal($holding->amount, $currency);
            $holding->diff = $holding->balance->subtract($amount);
        }

        return view('livewire.invest-events.holdings', [
            'holdings' => $holdings,
            'wallet' => $wallet,
            'currency' => $currency,
        ]);
    }
}
