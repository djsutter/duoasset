@php
    /** @var \App\Data\Tax\TaxPoolLedgerEntryData $dto */
@endphp

<div>
    <label for="asset" class="block mb-2 font-medium">Select Asset:</label>
    <select wire:model.lazy="assetCode" id="asset" class="border rounded px-2 py-1">
        <option value="">-- Select an Asset --</option>
        @foreach($assets as $asset)
            <option value="{{ $asset }}">{{ $asset }}</option>
        @endforeach
    </select>

    <select wire:model.live="year"
            class="border rounded px-2 py-1 focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 dark:bg-zinc-800">
        @foreach ($this->yearOptions as $year)
            <option value="{{ $year }}">
                {{ $year }}
            </option>
        @endforeach
    </select>

    @if(!empty($this->ledgerEntries))
        <table class="table-auto border-collapse border border-gray-300 w-full text-xs">
            <thead class="bg-gray-100 sticky top-0 z-10">
            <tr>
                <th class="px-2 py-1 text-left">Date</th>
                <th class="px-2 py-1 text-left">Event</th>
                <th class="px-2 py-1 text-right">Qty Δ</th>
                <th class="px-2 py-1 text-right">Qty After</th>
                <th class="px-2 py-1 text-right">ACB Δ</th>
                <th class="px-2 py-1 text-right">ACB After</th>
                <th class="px-2 py-1 text-right">Unit Cost</th>
                <th class="px-2 py-1 text-right">Proceeds</th>
                <th class="px-2 py-1 text-right">ACB Allocated</th>
                <th class="px-2 py-1 text-right">ACB Reportable</th>
                <th class="px-2 py-1 text-right max-w-[90px]">Gain/Loss Before Denial</th>
                <th class="px-2 py-1 text-right max-w-[90px]">Gain/Loss After Denial</th>
                <th class="px-2 py-1 text-right">Denied Loss</th>
            </tr>
            </thead>
            <tbody>
            @foreach($this->ledgerEntries as $dto)
                <tr>
                    <td class="px-2 py-1 text-nowrap">{{ $dto->event_date->toDateString() }}</td>
                    <td class="px-2 py-1 hover:underline hover:cursor-pointer"
                        wire:click="dispatch('edit-transaction', {transactionId: {{ $dto->tx_id }} })"
                        wire:key="tx-{{ $dto->tx_id }}">{{ $dto->origin_event_type->value }}</td>
                    <td class="px-2 py-1 text-right">@assetQuantity($dto->quantity_delta)</td>
                    <td class="px-2 py-1 text-right">@assetQuantity($dto->quantity_after)</td>
                    <td class="px-2 py-1 text-right">@money($dto->acb_delta)</td>
                    <td class="px-2 py-1 text-right">@money($dto->acb_after)</td>
                    <td class="px-2 py-1 text-right">@money($dto->unit_cost_after)</td>
                    <td class="px-2 py-1 text-right">@money($dto->proceeds)</td>
                    <td class="px-2 py-1 text-right">@money($dto->acb_allocated)</td>
                    <td class="px-2 py-1 text-right">@money($dto->acb_reportable)</td>
                    <td class="px-2 py-1 text-right max-w-[90px]">@money($dto->capital_gain_loss_before_denial?->round(2))</td>
                    <td class="px-2 py-1 text-right max-w-[90px] {{ $dto->capital_gain_loss_after_denial && $dto->capital_gain_loss_after_denial->isNegative() ? 'text-red-600' : 'text-green-600' }}">
                        @money($dto->capital_gain_loss_after_denial?->round(2))
                    </td>
                    <td class="px-2 py-1 text-right">@money($dto->denied_loss?->round(2))</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="font-bold">
                <td colspan="7" class="px-2 py-1 text-right">Totals:</td>
                <td class="px-2 py-1 text-right">@money($this->totals['proceeds'])</td>
                <td class="px-2 py-1 text-right">@money($this->totals['acb_allocated'])</td>
                <td class="px-2 py-1 text-right">@money($this->totals['acb_reportable'])</td>
                <td class="px-2 py-1 text-right">@money($this->totals['gain_before_denial'])</td>
                <td class="px-2 py-1 text-right">@money($this->totals['capital_gain'])</td>
                <td class="px-2 py-1 text-right">@money($this->totals['denied_loss'])</td>
            </tr>
            </tfoot>
        </table>
    @else
        <p class="mt-4 text-gray-500">No ledger entries to display for this asset.</p>
    @endif
    <livewire:transactions.edit-modal/>
</div>
