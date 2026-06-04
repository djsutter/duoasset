@php
    /** @var \App\Data\Tax\Schedule3\Schedule3DispositionData $row */
@endphp
<div>
    <div>
        <h1 class="text-xl font-semibold">
            Asset Dispositions
        </h1>
    </div>

    <table class="w-full text-sm border-collapse mt-4">
        <thead>
        <tr>
            <th class="text-left">Date</th>
            <th class="text-left">Asset</th>
            <th class="text-right">Quantity Disposed</th>
            <th class="text-right">Proceeds</th>
            <th class="text-right">ACB</th>
            <th class="text-right">Gain (Loss)</th>
            <th class="text-right">Denied Loss</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($this->dispositions as $row)
            <tr x-rowlink="'{{ route('tax.schedule3.disposition', ['year' => $this->year, 'asset' => $this->asset, 'acbEvent' => $row->acb_event_id]) }}'"
                class="hover:bg-gray-50">
                <td class="text-left">{{ $row->date }}</td>
                <td class="text-left">{{ $this->asset }}</td>
                <td class="text-right">@assetQuantity($row->quantity)</td>
                <td class="text-right">@money($row->proceeds)</td>
                <td class="text-right">@money($row->acb_reportable)</td>
                <td class="text-right">@money($row->capital_gain_loss)</td>
                <td class="text-right">@money($row->denied_loss)</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr class="font-semibold border-t">
            <td colspan="2">Total</td>
            <td class="text-right">@assetQuantity($this->totals['quantity'])</td>
            <td class="text-right">@money($this->totals['proceeds'])</td>
            <td class="text-right">@money($this->totals['acb'])</td>
            <td class="text-right">@money($this->totals['gains'])</td>
            <td class="text-right">@money($this->totals['denied_losses'])</td>
        </tr>
        </tfoot>
    </table>
</div>
