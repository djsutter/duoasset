<div>
    <h1 class="mb-6">External Transactions</h1>

    {{-- Flash messages --}}
    @if (session()->has('success'))
        <div class="p-2 mb-4 text-green-700 bg-green-100 rounded">
            {{ session('success') }}
        </div>
    @endif

    <div class="text-right mb-4">
        <flux:button
            variant="primary"
            class="bg-blue-600 hover:bg-blue-800 text-white"
            wire:click="$dispatch('prompt-description')"
        >
            {{ __('Change Description') }}
        </flux:button>
        <flux:button variant="primary" class="bg-blue-600 hover:bg-blue-800 text-white" wire:click="joinSelected">{{ __('Join') }}</flux:button>
        <flux:button variant="primary" class="bg-blue-600 hover:bg-blue-800 text-white" wire:click="dispatch('edit-transaction', {transactionId: null })">{{ __('New Transaction') }}</flux:button>
        @php
        $colors = empty($replayLog) ? 'bg-blue-950 pointer-events-none text-gray-500' : 'bg-blue-600 hover:bg-blue-800 text-white';
        @endphp
        <flux:button
            variant="primary"
            wire:click="exportReplayLog"
            class="px-3 py-1 {{ $colors }} rounded disabled:opacity-50"
        >
            Export Replay Log
        </flux:button>
    </div>

    <table class="w-full">
        @foreach ($transactions as $transaction)
            <tr class="hover:bg-blue-100 dark:hover:bg-blue-900">
                <td><input type="checkbox" value="{{ $transaction->id }}" wire:model="sel_tx"></td>
                <td class="px-2" wire:click="dispatch('edit-transaction', {transactionId: {{ $transaction->id }} })">edit</td>
                <td class="px-4 text-nowrap">{{ $transaction->transaction_at->format('Y-m-d H:i:s') }}</td>
                <td class="px-4">{{ $transaction->tx_type->value }}</td>
                <td class="px-4">{{ $transaction->description }}</td>
                <td class="px-4">{{ $transaction->wallet->platform->name }}</td>
                <td class="px-4 text-right">@money($transaction->amount) {{ $transaction->amount?->currency }}</td>
                <td class="px-4 text-right">@money($transaction->foreign_amount) {{ $transaction->foreign_amount?->currency }}</td>
            </tr>
        @endforeach
    </table>
    <livewire:transactions.edit-modal/>
    <script>
        document.addEventListener('prompt-description', function () {
            const newDesc = prompt("Enter a new description for the selected transactions:");
            if (newDesc !== null && newDesc.trim() !== "") {
                Livewire.dispatch('do-change-description', { description: newDesc });
            }
        });
    </script>
</div>
