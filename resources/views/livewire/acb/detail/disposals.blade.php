<div>
    @if (empty($data))
        <div class="p-3 mb-4 bg-gray-100 dark:bg-zinc-600 text-gray-800 rounded">No disposals for this asset.</div>
    @else
        <table class="min-w-full bg-white dark:bg-zinc-600 shadow rounded-lg mb-6">
            <thead class="dark:bg-zinc-600">
            <tr class="bg-gray-100 dark:bg-zinc-600 text-left text-sm font-semibold">
                <th class="px-4 py-2">Date</th>
                <th class="px-4 py-2">Quantity</th>
                <th class="px-4 py-2">Proceeds (CAD)</th>
                <th class="px-4 py-2">ACB Allocated (CAD)</th>
                <th class="px-4 py-2">Expenses (CAD)</th>
                <th class="px-4 py-2">Gain/Loss (CAD)</th>
            </tr>

            </thead>
            <tbody class="text-sm dark:text-gray-300">
            @foreach ($data as $d)
                <tr class="border-b dark:bg-zinc-800 dark:border-zinc-700">
                    <td class="px-4 py-2">{{ $d->date->format('Y-m-d') }}</td>
                    <td class="px-4 py-2">{{ $d->quantity_disposed->amount }}</td>
                    <td class="px-4 py-2">@money($d->proceeds)</td>
                    <td class="px-4 py-2">@money($d->acb_allocated)</td>
                    <td class="px-4 py-2">@money($d->expenses)</td>
                    <td class="px-4 py-2">@money($d->gain_or_loss)</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
