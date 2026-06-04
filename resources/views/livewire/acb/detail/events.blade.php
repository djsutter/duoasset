<div class="overflow-x-auto bg-white dark:bg-zinc-600 shadow rounded-lg">
    <table class="min-w-full">
        <thead class="bg-gray-50 dark:bg-zinc-600 text-left text-sm font-semibold">
        <tr>
            <th class="px-4 py-3">Date</th>
            <th class="px-4 py-3">Type</th>
            <th class="px-4 py-3">Quantity</th>
            <th class="px-4 py-3">Cost (CAD)</th>
        </tr>
        </thead>
        <tbody class="text-sm dark:text-gray-300">
        @forelse($data as $event)
            <tr class="border-t dark:bg-zinc-800 dark:border-zinc-700">
                <td class="px-4 py-3">{{ $event->event_at->format('Y-m-d H:i') }}</td>
                <td class="px-4 py-3">{{ ucfirst($event->event_type->value) }}</td>
                <td class="px-4 py-3">{{ $event->quantity->amount }}</td>
                <td class="px-4 py-3">@money($event->cost_amount)</td>
            </tr>
        @empty
            <tr>
                <td class="px-4 py-6 text-center text-gray-500" colspan="4">No events found.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
