<?php

namespace App\Services;

use App\Data\InvestAccountData;
use App\Models\Invest\Account as InvestAccount;
use App\Models\Invest\CurrencyPair;
use App\Models\Invest\Holding;
use App\Models\InvestEvent;
use App\Models\Platform;
use App\Types\Money;

class InvestAppService
{
    protected array $currencyPairs = [];

    protected array $accountsById = [];

    protected array $walletMap = [];

    protected WalletService $walletService;

    public function __construct()
    {
        $this->walletService = app(WalletService::class);
    }

    public function generate(): void
    {
        InvestEvent::truncate();

        $this->mapAccounts();
        $this->currencyPairs = CurrencyPair::where('id', '>', 2)->get()->pluck('currency', 'id')->toArray();
        $currencyPairIds = array_keys($this->currencyPairs);

        foreach (InvestAccount::all() as $account) {
            if (! in_array($account->currency, $this->currencyPairs)) {
                continue;
            }
            $this->accountsById[$account->id] = InvestAccountData::fromModel($account);
        }

        // Note: Prior to 2021-04-16, we did not have quantities in decimal amounts.

        $lastDate = null;
        $holdings = Holding::whereIn('currency_pair_id', $currencyPairIds)
            ->where('date', '>=', '2021-04-16')
            ->orderBy('date')
            ->get();
        foreach ($holdings as $holding) {
            if ($holding->date != $lastDate) {
                $this->recordChanges($lastDate);
                $this->resetBalances();
                $lastDate = $holding->date;
                $newDate = $holding->date;
            }
            $account = $this->accountsById[$holding->account_id];
            $account->balance = Money::fromDecimal($holding->amount, $account->currency);
        }
        $this->recordChanges($newDate);
    }

    public function mapAccounts(): array
    {
        $investAccounts = [
            'Shakepay BTC' => ['Shakepay', 'BTC', null],
            'Shakepay CDN' => ['Shakepay', 'CAD', null],
            'Atomic Wallet BTC' => ['Atomic Wallet', 'BTC', null],
            'Atomic Wallet XMR' => ['Atomic Wallet', 'XMR', null],
            'Binance BTC' => ['Binance', 'BTC', null],
            'Shakepay ETH' => ['Shakepay', 'ETH', null],
            'Atomic Wallet ETH' => ['Atomic Wallet', 'ETH', null],
            'Atomic Wallet XRP' => ['Atomic Wallet', 'XRP', null],
            'Atomic Wallet ADA' => ['Atomic Wallet', 'ADA', null],
            'Atomic Wallet BUSD' => ['Atomic Wallet', 'BUSD', null],
            'Treasure Chest ARRR' => ['Treasure Chest', 'ARRR', null],
            'TradeOgre BTC' => ['TradeOgre', 'BTC', null],
            'Exodus BTC' => ['Exodus', 'BTC', null],
            'Exodus XMR' => ['Exodus', 'XMR', null],
            'TradeOgre ARRR' => ['TradeOgre', 'ARRR', null],
            'TradeOgre XMR' => ['TradeOgre', 'XMR', null],
            'TradeOgre DERO' => ['TradeOgre', 'DERO', null],
            'TradeOgre XEQ' => ['TradeOgre', 'XEQ', null],
            'TradeOgre AVN' => ['TradeOgre', 'AVN', null],
            'Avian Wallet AVN' => ['Avian', 'AVN', null],
            'Raptoreum wallet RTM' => ['Raptoreum', 'RTM', null],
            'Monero wallet XMR' => ['Monero', 'XMR', null],
            'TradeOgre LTC' => ['TradeOgre', 'LTC', null],
            'Equilibria wallet XEQ' => ['Equilibria', 'XEQ', null],
            'TradeOgre USDT' => ['TradeOgre', 'USDT', null],
            'TradeOgre RXD' => ['TradeOgre', 'RXD', null],
            'Bitcoin Core HODL BTC' => ['Bitcoin Core', 'BTC', 'Hodlings'],
            'KuCoin BTC' => ['KuCoin', 'BTC', null],
            'KuCoin XMR' => ['KuCoin', 'XMR', null],
            'KuCoin USDT' => ['KuCoin', 'USDT', null],
            'Bitcoin Core TO BTC' => ['Bitcoin Core', 'BTC', 'TradeOgre'],
        ];

        foreach ($investAccounts as $investName => $accountData) {
            [$platformName, $currency, $walletName] = $accountData;
            $platform = Platform::where('name', $platformName)->first();
            if (is_null($platform)) {
                \Log::error("Can't resolve platform $platformName");

                continue;
            }
            $this->walletMap[$investName] = $this->walletService->getWallet($platform, $currency, $walletName);
        }

        return $investAccounts;
    }

    private function resetBalances(): void
    {
        foreach ($this->accountsById as $id => $account) {
            $account->prevBalance = $account->balance;
            $account->balance = Money::zero($account->currency);
        }
    }

    public function recordChanges($date): void
    {
        $accountsById = $this->accountsById;
        foreach ($accountsById as $id => $account) {
            if (! $account->balance->equals($account->prevBalance)) {
                $localWallet = $this->walletMap[$account->name];
                if (is_null($localWallet)) {
                    \Log::info("can't find local account for $account->name");

                    continue;
                }
                $message = null;
                if ($localWallet->getBalance($date)->equals($account->balance)) {
                    $message = 'balance match';
                } else {
                    $dayBefore = $date->clone()->subDay();
                    if ($localWallet->getBalance($dayBefore)->equals($account->balance)) {
                        $message = 'match day before';
                    }
                }

                InvestEvent::create([
                    'date' => $date,
                    'wallet_id' => $localWallet->id,
                    'prev_balance' => $account->prevBalance,
                    'new_balance' => $account->balance,
                    'change' => $account->balance->subtract($account->prevBalance),
                    'message' => $message,
                ]);
            }
        }
    }
}
