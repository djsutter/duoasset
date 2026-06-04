<div class="p-4 space-y-6">

    <!-- Summary Header -->
    <h2 class="text-2xl font-bold">
        Disposition: {{ $this->asset }} ({{ $year }})
    </h2>

    <!-- Summary Details -->
    @php
        /** @var \App\Data\Tax\Schedule3\Schedule3DispositionData $disposition */
        $disposition = $this->disposition;
    @endphp
    <div class="grid grid-cols-2 gap-4 bg-gray-50 p-4 rounded shadow-sm">
        <p><strong>ACB Event ID:</strong> {{ $disposition->acb_event_id }}</p>
        <p><strong>Disposition Date:</strong> {{ $disposition->date->format('Y-m-d') }}</p>
        <p><strong>Quantity Disposed:</strong> @assetQuantity($disposition->quantity)</p>
        <p><strong>Proceeds:</strong> @money($disposition->proceeds)</p>
        <p><strong>ACB:</strong> @money($disposition->acb_reportable)</p>
        <p><strong>Outlays:</strong> @money($disposition->outlays)</p>
        <p><strong>Gain/Loss before denial:</strong> @money($disposition->capital_gain_loss_before_denial)</p>
        <p><strong>Reportable Gain/Loss:</strong> @money($disposition->capital_gain_loss)</p>
        <p><strong>Disposition Type:</strong> {{ $disposition->disposition_type }}</p>
        <p><strong>Superficial Loss:</strong> {{ $disposition->is_superficial_loss ? 'Yes' : 'No' }}</p>
    </div>

    <!-- Lot Breakdown Table -->
    @if (! empty($this->lotBreakdown))
        <h3 class="text-xl font-semibold mt-4">Lot Breakdown</h3>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 border">
                <thead class="bg-gray-100">
                <tr>
                    <th class="px-3 py-2 text-left text-sm font-medium">ID</th>
                    <th class="px-3 py-2 text-left text-sm font-medium">Lot Date</th>
                    <th class="px-3 py-2 text-left text-sm font-medium">Acquired Units</th>
                    <th class="px-3 py-2 text-left text-sm font-medium">Acquired Unit Cost</th>
                    <th class="px-3 py-2 text-left text-sm font-medium">Disposed Quantity</th>
                    <th class="px-3 py-2 text-left text-sm font-medium">ACB Used Amount</th>
                    <th class="px-3 py-2 text-left text-sm font-medium">Remaining Quantity</th>
                </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                @foreach ($this->lotBreakdown as $lot)
                    <tr>
                        <td class="px-3 py-2">{{ $lot->lot_id }}</td>
                        <td class="px-3 py-2">{{ $lot->acquired_at->format('Y-m-d') }}</td>
                        <td class="px-3 py-2">@assetQuantity($lot->acquired_quantity)</td>
                        <td class="px-3 py-2">@money($lot->acquired_unit_cost)</td>
                        <td class="px-3 py-2">@assetQuantity($lot->disposed_quantity)</td>
                        <td class="px-3 py-2">@money($lot->acb_used_amount)</td>
                        <td class="px-3 py-2">@assetQuantity($lot->remaining_quantity)</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <!-- Superficial Loss Section -->
    @if ($this->superficialLoss)
        <h3 class="text-xl font-semibold mt-6">Superficial Loss</h3>
        <ul class="list-disc pl-6 space-y-1">
            <li><strong>Denied Loss:</strong> @money($this->superficialLoss->denied_loss_amount)</li>
            <li><strong>Allowable Loss:</strong> @money($this->superficialLoss->capital_gain_loss_after_denial)</li>
            <li><strong>Reason:</strong> {{ $this->superficialLoss->denial_reason }}</li>
        </ul>
    @endif
</div>
