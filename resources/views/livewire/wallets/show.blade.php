<div>
    <h1 class="mb-6">{{ $platform.' '.$wallet->name }}</h1>

    {{-- Flash messages --}}
    @if (session()->has('success'))
        <div class="p-2 mb-4 text-green-700 bg-green-100 rounded">
            {{ session('success') }}
        </div>
    @endif

    <div class="text-right mb-4">
        <flux:button variant="primary" class="bg-blue-600 hover:bg-blue-800 text-white" wire:click="dispatch('edit-transaction', {transactionId: null })">
            {{ __('New Transaction') }}
        </flux:button>
    </div>

    <table class="w-full">
    @foreach ($transactions as $transaction)
        <tr class="hover:bg-blue-100 dark:hover:bg-gray-600 dark:text-gray-300" wire:click="dispatch('edit-transaction', {transactionId: {{ $transaction->transactionId }} })" wire:key="tx-{{ $transaction->transactionId }}">
            <td class="px-4 text-nowrap">{{ $transaction->transactionAt->format('Y-m-d H:i') }}</td>
            <td class="px-4">{{ $transaction->tx_type->value }}</td>
            <td class="px-4">{{ $transaction->description }}</td>
            <td class="px-4">{{ $transaction->otherWallet }}</td>
            <td class="px-4 text-right">@money($transaction->amount)</td>
            <td class="px-4 text-right">@money($transaction->balance)</td>
        </tr>
    @endforeach
    </table>
    <livewire:transactions.edit-modal/>
</div>
