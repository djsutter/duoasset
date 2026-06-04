<div>
    <div class="mb-4">
        <div class="inline-block mr-4">
            <label for="asset" class="font-medium">Select Asset:</label><br>
            <select wire:model.lazy="assetCode" id="asset" class="border rounded px-2 py-1">
                <option value="">-- Select an Asset --</option>
                @foreach($assets as $asset)
                    <option value="{{ $asset }}">{{ $asset }}</option>
                @endforeach
            </select>
        </div>

        <div class="inline-block mr-4">
            <label>Tax Year:</label><br>
            <select wire:model.live="year" class="border rounded px-2 py-1 focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 dark:bg-zinc-800">
                @foreach ($this->yearOptions as $year)
                    <option value="{{ $year }}">
                        {{ $year }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($report)
        @foreach ($report->assets as $asset)
            <div class="border rounded p-4 mb-6">

                <h2 class="text-lg font-semibold mb-3">
                    {{ $asset->asset_code }}
                </h2>

                <table class="w-1/2">
                    <thead>
                    <tr>
                        <th class="text-left">Description</th>
                        <th class="text-right">Quantity ({{ $asset->asset_code }})</th>
                        <th class="text-right">Total CAD</th>
                    </tr>
                    </thead>
                    <tbody>

                    <tr>
                        <td class="text-left">
                            Opening balance (Jan 1, {{ $report->tax_year }})
                        </td>
                        <td class="text-right">
                            @assetQuantity($asset->opening_quantity)
                        </td>
                        <td class="text-right">
                            @money($asset->opening_acb)
                        </td>
                    </tr>

                    <tr>
                        <td class="text-left">Purchases / Acquisitions</td>
                        <td class="text-right">
                            @assetQuantity($asset->quantity_acquired)
                        </td>
                        <td class="text-right">
                            @money($asset->acb_added)
                        </td>
                    </tr>

                    <tr>
                        <td class="text-left">Dispositions / Sales</td>
                        <td class="text-right">
                            @assetQuantity($asset->quantity_disposed)
                        </td>
                        <td class="text-right">
                            @money($asset->proceeds)
                        </td>
                    </tr>

                    <tr>
                        <td class="text-left">ACB of Dispositions</td>
                        <td class="text-right">-</td>
                        <td class="text-right">
                            @money($asset->acb_of_dispositions)
                        </td>
                    </tr>

                    <tr>
                        <td class="text-left">Net gain (Proceeds - ACB)</td>
                        <td class="text-right">-</td>
                        <td class="text-right">
                            @money($asset->gain_or_loss)
                        </td>
                    </tr>

                    <tr>
                        <td class="text-left">Denied losses (superficial)</td>
                        <td class="text-right">-</td>
                        <td class="text-right">
                            @money($asset->denied_loss)
                        </td>
                    </tr>

                    <tr>
                        <td class="text-left">
                            Closing balance (Dec 31, {{ $report->tax_year }})
                        </td>
                        <td class="text-right">
                            @assetQuantity($asset->closing_quantity)
                        </td>
                        <td class="text-right">
                            @money($asset->closing_acb)
                        </td>
                    </tr>

                    </tbody>
                </table>
            </div>
        @endforeach
    @endif
</div>
