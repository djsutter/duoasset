@php
$labelResolver = app(\App\Services\Reports\Acb\AcbAuditLabelResolver::class);
@endphp
<div>
    {{-- Header --}}
    <div>
        <h1 class="text-xl font-semibold">
            ACB Audit Ledger
        </h1>
        <p class="text-sm text-gray-600">
            Audit ledger to provide raw data to support ACB calculations.
        </p>
    </div>

    <select wire:model.live="assetCode" class="border rounded px-2 py-1 focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 dark:bg-zinc-800">
        @foreach ($this->assetOptions as $asset)
            <option value="{{ $asset->asset_code }}">
                {{ $asset->asset_code }} — {{ $asset->asset_name }}
            </option>
        @endforeach
    </select>

    <table class="w-full text-sm border-collapse mt-4">
        <thead>
        <tr class="border-b">
            <th>Date</th>
            <th>Event</th>
            <th>Tx</th>
            <th class="text-right">Qty Δ</th>
            <th class="text-right">Qty After</th>
            <th class="text-right">ACB Δ</th>
            <th class="text-right">ACB After</th>
            <th class="text-right">Unit Cost</th>
            <th class="text-right">Proceeds</th>
            <th class="text-right">ACB Allocated</th>
            <th class="text-right">Gain / Loss</th>
            <th class="text-right">DL Balance</th>
        </tr>
        </thead>
        <tbody>
        @if ($this->auditLedger)
            @php
                $annotations = $this->auditLedger->annotations;
                $diagnostics = $this->auditLedger->diagnostics;
            @endphp
            @foreach ($this->auditLedger->rows as $row)
                @php
                    $key = $row->rowkey();
                @endphp
                <tr class="border-b">
                    <td>{{ $row->event_at->toDateString() }}</td>
                    <td>{{ $labelResolver->labelFor($row) }}
                        @if ($key && isset($annotations[$key]))
                            <span title="{{ $annotations[$key]->message }}" class="w-[10px]">†</span>
                        @endif
                    </td>
                    <td>{{ $row->tx_id }}</td>
                    <td class="text-right">@assetQuantity($row->quantity_change)</td>
                    <td class="text-right">@assetQuantity($row->quantity_after)</td>
                    <td class="text-right">@money($row->acb_change)</td>
                    <td class="text-right">@money($row->acb_after)</td>
                    <td class="text-right">@money($row->unit_cost)</td>
                    <td class="text-right">@money($row->proceeds)</td>
                    <td class="text-right">@money($row->acb_allocated)</td>
                    <td class="text-right">@money($row->capital_gain_loss)</td>
                    <td class="text-right">
                        @if ($key && isset($diagnostics[$key]))
                            @money($diagnostics[$key]['latent_superficial_loss'])
                        @endif
                    </td>
                </tr>
            @endforeach
        @endif
        </tbody>
    </table>
</div>
