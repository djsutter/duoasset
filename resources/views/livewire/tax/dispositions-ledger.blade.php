<div>
    <label for="asset">Select Asset:</label>
    <select wire:model.live="selectedAsset" id="asset">
        <option value="">-- All Assets --</option>
        @foreach($assets as $asset)
            <option value="{{ $asset }}">{{ $asset }}</option>
        @endforeach
    </select>

    @foreach($taxPools as $pool)
        <h3>{{ $pool->asset_code }} ({{ $pool->currency }})</h3>
        <p>Total Quantity: @assetQuantity($pool->total_quantity)</p>
        <p>Total ACB: @money($pool->total_acb)</p>

        <table class="table-auto border border-gray-300">
            <thead>
            <tr class="bg-gray-100">
                <th class="px-2 py-1">Date</th>
                <th class="px-2 py-1">Quantity Disposed</th>
                <th class="px-2 py-1">Proceeds</th>
                <th class="px-2 py-1">ACB Allocated</th>
                <th class="px-2 py-1">Capital Gain</th>
            </tr>
            </thead>
            <tbody>
            @foreach($pool->dispositions as $d)
                <tr>
                    <td class="px-2 py-1">{{ $d->disposition_date->format('Y-m-d') }}</td>
                    <td class="px-2 py-1">@assetQuantity($d->quantity_disposed)</td>
                    <td class="px-2 py-1">@money($d->proceeds)</td>
                    <td class="px-2 py-1">@money($d->acb_allocated)</td>
                    <td class="px-2 py-1">@money($d->capital_gain_loss_after_denial)</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach
</div>
