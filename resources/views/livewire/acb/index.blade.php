<div class="p-4">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-semibold">ACB — Assets</h1>
        <div class="text-sm text-gray-600 dark:text-gray-400">Total assets: {{ $assets->count() }}</div>
    </div>

    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Assets</h1>

        <flux:button variant="primary" class="bg-blue-600 hover:bg-blue-800 text-white" wire:click="rebuildAllAssets">
            {{ $assets->count() == 0 ? __('Build All Assets') : __('Rebuild All Assets') }}
        </flux:button>
    </div>

    <div class="overflow-x-auto bg-white shadow rounded-lg">
        <table class="min-w-full">
            <thead class="bg-gray-50 dark:bg-zinc-600 text-left text-sm font-semibold">
            <tr>
                <th class="px-4 py-3">Asset</th>
                <th class="px-4 py-3">Quantity</th>
                <th class="px-4 py-3">Total ACB (CAD)</th>
                <th class="px-4 py-3">Avg Cost / Unit (CAD)</th>
                <th class="px-4 py-3">Last Tx</th>
                <th class="px-4 py-3">Actions</th>
            </tr>
            </thead>

            <tbody class="text-sm dark:text-gray-300">
            @forelse($assets as $asset)
                <tr class="border-t dark:bg-zinc-800 dark:border-zinc-700">
                    <td class="px-4 py-3">
                        <a href="{{ route('acb.show', $asset) }}" class="text-blue-600 dark:text-sky-400 hover:underline">
                            {{ $asset->asset_code }}
                        </a>
                    </td>

                    <td class="px-4 py-3">
                        {{ $asset->quantity->amount }}
                    </td>

                    <td class="px-4 py-3">
                        {{ $asset->acb?->toDecimal() ?? '0' }}
                    </td>

                    <td class="px-4 py-3">
                        {{ optional($asset->acbPerUnit())->toDecimal() ?? '0' }}
                    </td>

                    <td class="px-4 py-3">
                        {{ optional($asset->last_transaction_at)->format('Y-m-d') ?? '-' }}
                    </td>

                    <td class="px-4 py-3">
                        <a href="{{ route('acb.show', $asset) }}" class="text-sm text-blue-600 dark:text-sky-400 hover:underline mr-2">View</a>
                        <button
                            wire:click="rebuildAsset({{ $asset->id }})"
                            class="text-sm text-gray-600 dark:text-sky-600 hover:text-gray-800 hover:dark:text-sky-700"
                        >
                            Rebuild
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="px-4 py-6 text-center text-gray-500" colspan="6">
                        No assets found.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
