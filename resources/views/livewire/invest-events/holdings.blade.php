<div class="p-2">
    Invest Holdings

    <div class="w-1/4">
        <x-form-select :options="getWalletList()" wire:model.live="wallet_id" />
    </div>

    <table class="w-full">
        <thead>
            <tr>
                <th class="px-1">Date</th>
                <th class="px-1">Holdings</th>
                <th></th>
                <th class="px-1">Acct Balance</th>
                <th class="px-1">Diff</th>
                <th class="px-1">Account</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($holdings as $holding)
            <tr>
                <td class="px-1">{{ $holding->date->format('Y-m-d') }}</td>
                <td class="text-right px-1">{{ $holding->amount }}</td>
                <td>
                    @if (! $holding->diff->isZero())
                    <flux:icon.arrow-left
                        class="inline relative bottom-1 ml-1 hover:text-red-500 hover:bg-red-200"
                        variant="mini"
                        wire:click="apply('{{ $holding->date->format('Y-m-d') }}', '{{ $holding->balance->format() }}')"
                    />
                    @endif
                </td>
                <td class="text-right px-1">{{ $holding->balance->format() }}</td>
                <td class="text-right px-1">
                    @if ($holding->diff->isZero())
                        {{ $holding->diff->format() }}
                    @else
                        @php
                        if ($holding->diff->isNegative()) {
                            $type = 'receive';
                            $amount = $holding->diff->negated()->toDecimal();
                            $description = "Receive $currency";
                        } else {
                            $type = 'send';
                            $amount = $holding->diff->toDecimal();
                            $description = "Send $currency";
                        }
                        @endphp
                        <div
                            wire:click="dispatch('edit-transaction', {
                                init: {
                                    type: '{{ $type }}',
                                    date: '{{ $holding->date->format('Y-m-d') }}',
                                    amount: '{{ $amount }}',
                                    currency: '{{ $currency }}',
                                    walletId: {{ $wallet_id }},
                                    description: '{{ $description }}',
                                }
                            })"
                            class="cursor-pointer"
                        >
                            {{ $holding->diff->format() }}
                        </div>
                    @endif
                </td>
                <td class="px-1">{{ $holding->account->name }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <livewire:transactions.edit-modal/>
</div>
