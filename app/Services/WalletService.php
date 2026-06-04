<?php

namespace App\Services;

use App\Data\Views\ExternalTransactionViewData;
use App\Data\WalletTxnData;
use App\Models\Platform;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Types\Money;
use Illuminate\Support\Collection;

class WalletService
{
    protected $walletCache = [];

    protected array $externalWallets = [];

    protected array $feeWallets = [];

    public function createWallet(Platform $platform, string $walletName): Wallet
    {
        return $platform->wallets()->create([
            'name' => $walletName,
            'currency' => $walletName,
        ]);
    }

    public function getOrCreateWallet(Platform $platform, string $walletName): Wallet
    {
        if ($wallet = $this->getWallet($platform, $walletName)) {
            return $wallet;
        }

        return $this->createWallet($platform, $walletName);
    }

    public function getWallet(Platform $platform, string $walletName): ?Wallet
    {
        $cacheKey = $this->makeCacheKey($platform->name, $walletName);

        // Return from resolver-level cache if available
        if (isset($this->walletCache[$cacheKey])) {
            return $this->walletCache[$cacheKey];
        }

        if ($wallet = $platform->wallets()->where('name', $walletName)->first()) {
            $this->walletCache[$cacheKey] = $wallet;
        }

        return $wallet;
    }

    public function getOrCreateExternalWallet(string $currency): Wallet
    {
        if (! isset($this->externalWallets[$currency])) {
            if (! $wallet = Wallet::where('type', 'external')->where('currency', $currency)->first()) {
                $wallet = Wallet::create([
                    'name' => 'External:'.$currency,
                    'currency' => $currency,
                    'type' => 'external',
                ]);
            }
            $this->externalWallets[$currency] = $wallet;
        }

        return $this->externalWallets[$currency];
    }

    public function getExternalTransactions(): Collection
    {
        $reportingCurrency = getReportingCurrency();
        $extWalletIds = Wallet::where('type', 'external')->where('currency', '!=', $reportingCurrency)->pluck('id')->toArray();
        $transactions = Transaction::query()
            ->whereHas('entries', fn ($q) => $q->whereIn('wallet_id', $extWalletIds))
            ->orderBy('transaction_at')
            ->get();

        $dtoCollection = $transactions->map(function ($transaction) use ($extWalletIds) {
            $entry = $transaction->entries()->whereIn('entry_type', ['in', 'out'])->whereNotIn('wallet_id', $extWalletIds)->first();

            return ExternalTransactionViewData::fromModel(
                $transaction,
                $entry,
            );
        });

        return $dtoCollection;
    }

    public function getFeeWallet(string $currency): Wallet
    {
        if (! isset($this->feeWallets[$currency])) {
            if (! $wallet = Wallet::where('type', 'fee')->where('currency', $currency)->first()) {
                $wallet = Wallet::create([
                    'name' => 'Fee:'.$currency,
                    'currency' => $currency,
                    'type' => 'fee',
                ]);
            }
            $this->feeWallets[$currency] = $wallet;
        }

        return $this->feeWallets[$currency];
    }

    public function getTransactions(Wallet $wallet): Collection
    {
        $transactions = Transaction::query()
            ->whereHas('entries', fn ($q) => $q->where('wallet_id', $wallet->id))
            ->orderBy('transaction_at', 'asc')
            ->get();

        $foreign = $wallet->currency != getReportingCurrency();
        $balance = Money::zero($wallet->currency);
        $dtoCollection = $transactions->map(function ($transaction) use (&$balance, $wallet, $foreign) {
            $totalAmount = Money::zero($wallet->currency);
            foreach ($transaction->entries()->where('wallet_id', $wallet->id)->get() as $entry) {
                if ($amount = $foreign ? $entry->foreign_amount : $entry->amount) {
                    $totalAmount = $totalAmount->add($amount);
                }
            }
            $balance = $balance->add($totalAmount);
            if ($otherEntry = $transaction->entries()->where('wallet_id', '!=', $wallet->id)->first()) {
                $otherWallet = $otherEntry->wallet;
                $counterParty = $otherWallet->wallet?->platform ? $otherWallet->wallet->platform->name : $otherWallet->name;
            } else {
                // This should not happen, but let's handle it gracefully
                \Log::error("No 'other' entry on transaction $transaction->id");
                $counterParty = null;
            }

            return WalletTxnData::fromModel(
                $transaction,
                $totalAmount,
                $balance,
                $counterParty,
            );
        });

        return $dtoCollection;
    }

    protected function makeCacheKey(string $platformName, string $asset, ?string $walletName = null): string
    {
        $platformName = strtolower(preg_replace('/\W/', '', $platformName));
        $asset = strtoupper(trim($asset));
        $walletName = $walletName ? strtoupper(trim($walletName)) : null;

        return $platformName.'_'.$asset.($walletName ? '_'.$walletName : '');
    }
}
