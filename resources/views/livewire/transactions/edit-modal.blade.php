@use(App\Enums\TransactionType)

<x-modal name="edit-wallet" class="w-[800px]" :dismissible="false" wire:model="showModal" wire:keyup.enter="submit">
    @php
    $isLocked = $transaction_id ? true : false;
    @endphp
    <form wire:submit.prevent="save" class="space-y-6">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ isset($transaction_id) ? __('Edit Transaction') : __('New Transaction') }}</flux:heading>
            </div>

            <div>
                <div class="w-full mb-8">
                    <x-txn-type-button type="trade" :locked="$isLocked">Trade</x-txn-type-button>
                    <x-txn-type-button type="receive" :locked="$isLocked">Receive</x-txn-type-button>
                    <x-txn-type-button type="send" :locked="$isLocked">Send</x-txn-type-button>
                    <x-txn-type-button type="transfer" :locked="$isLocked">Transfer</x-txn-type-button>
                </div>
                <div class="min-w-[600px] min-h-[450px]">
                    @includeWhen($tx_type == TransactionType::Receive, 'livewire.transactions._edit-receive')
                    @includeWhen($tx_type == TransactionType::Send, 'livewire.transactions._edit-send')
                    @includeWhen($tx_type == TransactionType::Trade, 'livewire.transactions._edit-trade')
                    @includeWhen($tx_type == TransactionType::Transfer, 'livewire.transactions._edit-transfer')
                </div>
            </div>

            <div class="flex">
                <p>TxId: {{ $transaction_id }}</p>
                <flux:spacer />
                <flux:button type="button" variant="filled" wire:click="$set('showModal', false)">{{ __('Cancel') }}</flux:button>
                @if ($transaction_id)
                    <flux:button type="button" variant="danger" wire:click="delete({{ $transaction_id }})">{{ __('Delete') }}</flux:button>
                @endif
                <flux:button type="submit" variant="primary" class="ml-4 bg-blue-600 hover:bg-blue-800 text-white">{{ __('Save changes') }}</flux:button>
            </div>
        </div>
    </form>
</x-modal>
