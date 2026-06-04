<div>
    <h1>Transactions</h1>

    <div class="w-full">
        <table class="w-full">
            <thead>

            </thead>
            <tbody>
            @foreach ($transactions as $transaction)
                <tr class="bg-sky-50 even:bg-sky-100 dark:bg-slate-950 even:dark:bg-gray-900" wire:click="dispatch('edit-transaction', {transactionId: {{ $transaction->tx_id }} })">
                    @include('livewire.reports.transactions._'.$transaction->tx_type->value)
                </tr>
            @endforeach
            </tbody>
        </table>
        {{ $transactionQuery->render() }}
    </div>
    <livewire:transactions.edit-modal/>
</div>

