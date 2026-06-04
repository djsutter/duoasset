<?php

namespace App\Livewire\Reports;

use App\Data\Reports\TransactionListData;
use App\Models\Transaction;
use Livewire\Component;
use Livewire\WithPagination;

class Transactions extends Component
{
    use WithPagination;

    public function render()
    {
        $transactionQuery = Transaction::orderBy('transaction_at')
            ->paginate(25);

        $transactions = $transactionQuery->map(fn ($transaction) => TransactionListData::fromModel($transaction));

        return view('livewire.reports.transactions.index', [
            'transactions' => $transactions,
            'transactionQuery' => $transactionQuery,
        ]);
    }
}
