<div class="w-full">
    <h2 class="font-bold mb-8">Import Stage</h2>

    @php
        $statusMap = [
            'unmatched' => [
                'inactive' => 'text-gray-600 bg-gray-100 hover:bg-gray-200',
                'active'   => 'bg-gray-700 text-white',
            ],
            'automatched' => [
                'inactive' => 'text-blue-700 bg-blue-100 hover:bg-blue-200',
                'active'   => 'bg-blue-700 text-white',
            ],
            'matched' => [
                'inactive' => 'text-green-700 bg-green-100 hover:bg-green-200',
                'active'   => 'bg-green-700 text-white',
            ],
            'manual' => [
                'inactive' => 'text-teal-800 bg-teal-200 hover:bg-teal-300',
                'active'   => 'bg-teal-700 text-white',
            ],
            'confirmed' => [
                'inactive' => 'text-emerald-800 bg-emerald-200 hover:bg-emerald-300',
                'active'   => 'bg-emerald-700 text-white',
            ],
            'ignored' => [
                'inactive' => 'text-pink-700 bg-pink-100 hover:bg-pink-200',
                'active'   => 'bg-pink-600 text-white',
            ],
            'external' => [
                'inactive' => 'text-purple-700 bg-purple-100 hover:bg-purple-200',
                'active'   => 'bg-purple-700 text-white',
            ],
            'error' => [
                'inactive' => 'text-red-800 bg-red-200 hover:bg-red-300',
                'active'   => 'bg-red-700 text-white',
            ],
        ];

        $rowBgMap = [
            'unmatched'   => 'bg-gray-50',
            'automatched' => 'bg-blue-50',
            'matched'     => 'bg-green-50',
            'manual'      => 'bg-lime-50',
            'confirmed'   => 'bg-emerald-50',
            'ignored'     => 'bg-rose-50',
            'external'    => 'bg-purple-50 dark:bg-purple-950',
            'error'       => 'bg-red-50',
        ];
    @endphp

    <div class="grid grid-cols-2 my-2 mb-4">
        <p class="my-2">The import stage is used for matching transactions prior to final import.</p>
        <div class="text-right">
            <flux:button variant="primary" class="bg-blue-600 hover:bg-blue-800 text-white" wire:click="export">{{ __('Export') }}</flux:button>
            <flux:button variant="primary" class="bg-blue-600 hover:bg-blue-800 text-white" wire:click="autoMatch">{{ __('Auto match') }}</flux:button>
            <flux:button variant="primary" class="bg-blue-600 hover:bg-blue-800 text-white" wire:click="import">{{ __('Import') }}</flux:button>
        </div>
    </div>
    <div class="text-right mb-4">
        <button
                wire:click="toggleFilter('show_all')"
                class="px-3 py-1 rounded-full text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200">
            All
        </button>

        @php
            $filters = [
                'show_matched'     => 'matched',
                'show_unmatched'   => 'unmatched',
                'show_automatched' => 'automatched',
                'show_manual'      => 'manual',
                'show_external'    => 'external',
                'show_ignored'     => 'ignored',
                'show_error'       => 'error',
            ];
        @endphp

        @foreach ($filters as $filterVar => $status)
            @php $isActive = $$filterVar; @endphp
            <button
                    wire:click="toggleFilter('{{ $filterVar }}')"
                    class="px-3 py-1 rounded-full text-sm font-medium {{ $isActive ? $statusMap[$status]['active'] : $statusMap[$status]['inactive'] }}">
                {{ ucfirst(str_replace('_', ' ', $status)) }}
            </button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-lg shadow bg-white mb-5">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-500">
            <thead class="bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm uppercase">
            <tr>
                <th class="px-3 py-2 text-left">ID</th>
                <th class="px-3 py-2 text-left">Date</th>
                <th class="px-3 py-2 text-left">Type</th>
                <th class="px-3 py-2 text-left">Status</th>
                <th class="px-3 py-2 text-left">Description</th>
                <th class="px-3 py-2 text-left">Wallet</th>
                <th class="px-3 py-2 text-right">Amount</th>
            </tr>
            </thead>

            <tbody x-data="{ highlightedIds: [] }" class="divide-y divide-gray-100 dark:divide-gray-800 text-sm">
            @foreach ($transactions as $tx)
                @php
                    $rowClass = match($tx->status) {
                        'matched' => 'bg-green-50 dark:bg-gray-950 text-gray-500 dark:text-green-300',
                        'automatched' => 'bg-blue-50 dark:bg-gray-950 text-gray-500 dark:text-blue-300',
                        'unmatched' => 'bg-gray-50 dark:bg-gray-950 text-gray-500 dark:text-gray-300',
                        'ignored' => 'bg-red-50 dark:bg-gray-950 text-gray-500 dark:text-red-300',
                        'external' => 'bg-purple-50 dark:bg-gray-950 text-gray-500 dark:text-purple-300',
                        default => '',
                    };
                @endphp

                <tr
                    id="tx-{{ $tx->id }}"
                    :key="$tx->id"
                    :class="highlightedIds.includes({{ $tx->id }}) ? 'bg-yellow-100 dark:bg-blue-900' : '{{ $rowClass }}'"
                    class="transition-colors duration-200 hover:border-gray-300"
                >
                    <td class="px-3">
                        <div class="flex items-center">
                            <span>#{{ $tx->id }}</span>
                            @if ($tx->source === 'manual')
                                <!-- small plus icon or dot to indicate manually added -->
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                     fill="currentColor"
                                     class="w-3 h-3 text-blue-400"
                                     title="Manually added transaction">
                                    <path fill-rule="evenodd"
                                          d="M10 4a1 1 0 011 1v4h4a1 1 0 010 2h-4v4a1 1 0 01-2 0v-4H5a1 1 0 010-2h4V5a1 1 0 011-1z"
                                          clip-rule="evenodd" />
                                </svg>
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-2 ">
                        <div>{{ $tx->tx_at->format('Y-m-d') }}</div>
                        <div class="text-xs">{{ $tx->tx_at->format('H:i:s') }}</div>
                    </td>
                    <td class="px-3 py-2">{{ $tx->tx_type->value }}</td>

                    <!-- Status badge -->
                    <td class="px-3 py-2">
                        <div class="flex flex-col gap-0.5">
                            <span><x-status-badge :type="$tx->status" :id="$tx->id" /></span>
                            @if ($tx->match_id)
                                <span class="text-[11px] text-gray-500 leading-tight ml-2">
                                    @if ($tx->status === 'automatched')
                                        <button
                                            x-on:click="
                                                if (highlightedIds.includes({{ $tx->id }})) {
                                                    highlightedIds = []
                                                } else {
                                                    highlightedIds = [{{ $tx->id }}, {{ $tx->match_id }}]
                                                }"
                                            class="text-xs text-blue-600 underline hover:text-blue-800"
                                        >
                                            matched #{{ $tx->match_id }}
                                        </button>
                                    @endif
                            </span>
                            @endif
                        </div>
                    </td>

                    <!-- Description + inline expandable details for counterparty -->
                    <td class="px-3 py-2">
                        <div class="flex items-start gap-3">
                            <div class="flex-1">
                                <div>{{ $tx->description }}</div>

                                <!-- show small muted metadata under description if desired -->
                                <div class="text-xs mt-1">
                                    <span>{{ $tx->source ?? 'import' }}</span>
                                    <span class="mx-1">•</span>
                                    <span>{{ $tx->reference ?? '' }}</span>
                                </div>
                            </div>

                            <!-- Accessible details toggle for extra info (counterparty + amount) -->
                            @if($tx->platform2 || $tx->amount2)
                                <details class="text-xs">
                                    <summary class="cursor-pointer select-none list-none text-blue-600 hover:underline">
                                        Details
                                    </summary>
                                    <div class="mt-2 text-xs">
                                        <div><strong>Counterparty:</strong> {{ $tx->platform2 ?? '—' }}</div>
                                        <div><strong>Counter Amount:</strong> {{ $tx->amount2->format() ?? '—' }}</div>
                                    </div>
                                </details>
                            @endif
                        </div>
                    </td>

                    <td class="px-3 py-2">{{ $tx->platform1 }}</td>
                    <td class="px-3 py-2 text-right">{{ $tx->amount1?->format().' '.$tx->amount1?->currency }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{ $transactionPage->render() }}

    <style>
        details summary {
            margin-top: 0.8rem;
        }
        details[open] summary {
            margin-top: 0;
        }
    </style>
</div>
