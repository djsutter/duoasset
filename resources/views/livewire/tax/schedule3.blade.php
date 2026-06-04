<div>
    {{-- Header --}}
    <div>
        <h1 class="text-xl font-semibold">
            Schedule 3
        </h1>
    </div>

    <select wire:model.live="year"
            class="border rounded px-2 py-1 focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 dark:bg-zinc-800">
        @foreach ($this->yearOptions as $year)
            <option value="{{ $year }}">
                {{ $year }}
            </option>
        @endforeach
    </select>

    <select wire:model.live="method"
            class="border rounded ml-4 px-2 py-1 focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 dark:bg-zinc-800">
        <option value="{{ \App\Enums\Schedule3Method::Lot->value }}">Lot-based</option>
        <option value="{{ \App\Enums\Schedule3Method::Pool->value }}">Pooled ACB</option>
    </select>

    @if (count($this->schedule3->asset_rows))
        <table class="w-full text-sm border-collapse mt-4">
            <thead>
            <tr>
                <th>Tax Year</th>
                <th>Asset</th>
                <th>Description</th>
                <th class="text-right">Proceeds of Disposition</th>
                <th class="text-right">Adjusted Cost Base</th>
                <th class="text-right">Outlays and Expenses</th>
                <th class="text-right">Gain (Loss)</th>
                <th class="text-right">Denied Losses</th>
            </tr>
            </thead>
            <tbody>
            @php /** @var \App\Data\Tax\Schedule3\Schedule3AssetData $row */ @endphp
            @foreach ($this->asset_rows as $row)
                <tr x-rowlink="'{{ route('tax.schedule3.asset', [$row->tax_year, $row->asset_code]) }}'"
                    class="hover:bg-gray-50">
                    <td>{{ $row->tax_year }}</td>
                    <td>{{ $row->asset_code }}</a></td>
                    <td>{{ $row->description }}</td>
                    <td class="text-right">@money($row->proceeds)</td>
                    <td class="text-right">@money($row->acb_reportable)</td>
                    <td class="text-right">@money($row->outlays)</td>
                    <td class="text-right">@money($row->capital_gain_loss)</td>
                    <td class="text-right">@money($row->denied_loss_sum)</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="font-semibold border-t">
                <td colspan="3">Total</td>
                <td class="text-right">@money($this->schedule3->total_proceeds)</td>
                <td class="text-right">@money($this->schedule3->total_acb_reportable)</td>
                <td class="text-right">0.00</td>
                <td class="text-right">@money($this->schedule3->total_capital_gain_loss)</td>
                <td class="text-right">@money($this->schedule3->total_denied_loss)</td>
            </tr>
            </tfoot>
        </table>
    @endif
    @script
    <script>
        function rowClick(url) {
            const selection = window.getSelection().toString();

            if (selection.length > 0) {
                // User is selecting text — do nothing
                return;
            }

            window.location.href = url;
        }
    </script>
    @endscript
</div>
