<div class="overflow-x-auto bg-white dark:bg-zinc-600 shadow rounded-lg">
    <table class="min-w-full">
        <thead class="bg-gray-50 dark:bg-zinc-600 text-left text-sm font-semibold">
        <tr>
            <th class="px-4 py-3">Date</th>
            <th class="px-4 py-3">Quantity</th>
            <th class="px-4 py-3">ACB Total (CAD)</th>
            <th class="px-4 py-3">Avg Cost / Unit (CAD)</th>
        </tr>
        </thead>
        <tbody class="text-sm dark:text-gray-300">
        @forelse($data as $row)
            <tr class="border-t dark:bg-zinc-800 dark:border-zinc-700">
                <td class="px-4 py-3">{{ $row->date->format('Y-m-d') }}</td>
                <td class="px-4 py-3">{{ $row->quantity_total->amount }}</td>
                <td class="px-4 py-3">@money($row->acb_total)</td>
                <td class="px-4 py-3">@money($row->avg_cost_basis)</td>
            </tr>
        @empty
            <tr>
                <td class="px-4 py-6 text-center text-gray-500" colspan="4">No daily data found.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
