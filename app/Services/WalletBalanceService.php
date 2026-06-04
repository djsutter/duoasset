<?php

namespace App\Services;

use App\Models\DayBalance;
use App\Models\Wallet;
use App\Models\WalletEntry;
use App\Types\Money;
use Carbon\Carbon;

class WalletBalanceService
{
    /**
     * Calculate and update the day-balances for a wallet.
     *
     * @param  Wallet  $wallet  The wallet for which to calculate balances.
     * @param  Carbon|null  $fromDate  Optional start date for recalculating balances.
     */
    public function calculateBalance(Wallet $wallet, Carbon|string|null $fromDate = null): void
    {
        $fromDate = carbonize($fromDate)?->startOfDay();
        $isReportingCurrency = getReportingCurrency() == $wallet->currency;

        if (is_null($wallet->opening_balance)) {
            $wallet->opening_balance = Money::zero($wallet->currency);
        }

        // Step 1: Set initial balance.
        $wallet->balance = $fromDate
            ? $wallet->getBalanceBefore($fromDate)
            : $wallet->opening_balance;

        // Step 2: Fetch entries with applied transactions from fromDate (or all if null)
        $query = WalletEntry::where('wallet_id', $wallet->id);
        if ($fromDate) {
            $query->where('transaction_at', '>=', $fromDate);
        }
        $entries = $query->orderBy('transaction_at')->get();

        // Step 3: If no entries, delete day balances after fromDate and return.
        if ($entries->isEmpty()) {
            $wallet->dayBalances()
                ->when($fromDate, fn ($q) => $q->where('date', '>=', $fromDate->toDateString()))
                ->delete();
            $wallet->save();

            return;
        }

        // Step 4: Apply entries and collect balances per day.
        $balancesByDate = [];
        $grouped = $entries->groupBy(fn ($entry) => $entry->transaction_at->toDateString());
        foreach ($grouped as $date => $entriesForDay) {
            foreach ($entriesForDay as $entry) {
                if ($isReportingCurrency) {
                    $wallet->applyAmount($entry->amount);
                } elseif ($entry->foreign_currency == $wallet->currency) {
                    $wallet->applyAmount($entry->foreign_amount);
                }
            }
            $balancesByDate[$date] = $wallet->balance;
        }

        // Step 5: Sync DayBalances
        // Pro tip:
        // Use when() when you want to conditionally apply a clause only when a value exists.
        // Use where() when you know for sure that the value is present and valid (i.e. not null).
        $existingDayBalances = $wallet->dayBalances()
            ->when($fromDate, fn ($q) => $q->where('date', '>=', $fromDate))
            ->get()
            ->keyBy(fn ($db) => $db->date->toDateString());

        foreach ($balancesByDate as $date => $balance) {
            if (isset($existingDayBalances[$date])) {
                $existingDayBalances[$date]->update(['balance' => $balance]);
                unset($existingDayBalances[$date]);
            } else {
                DayBalance::create([
                    'wallet_id' => $wallet->id,
                    'date' => Carbon::parse($date)->toDateString(),
                    'balance' => $balance,
                ]);
            }
        }

        // Delete day balances that no longer have entries.
        foreach ($existingDayBalances as $dayBalance) {
            $dayBalance->delete();
        }

        // Save the new balance.
        $wallet->save();
    }
}
