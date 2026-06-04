@if (!empty($report->asset_totals))
    <div class="mt-10">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">
            CRA Totals by Asset
        </h3>

        <table class="w-full text-sm">
            <thead class="border-b text-gray-500">
            <tr>
                <th class="text-left">Asset</th>
                <th class="text-right">Proceeds</th>
                <th class="text-right">ACB</th>
                <th class="text-right">Expenses</th>
                <th class="text-right">Gain / Loss</th>
                <th class="text-right">Taxable</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($report->asset_totals as $total)
                <tr class="border-b last:border-0">
                    <td>{{ $total->asset_code }}</td>
                    <td class="text-right">{{ $total->proceeds_of_disposition->format() }}</td>
                    <td class="text-right">{{ $total->adjusted_cost_base->format() }}</td>
                    <td class="text-right">{{ $total->outlays_and_expenses->format() }}</td>
                    <td class="text-right">{{ $total->capital_gain_loss->format() }}</td>
                    <td class="text-right">{{ $total->taxable_capital_gain->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@if (!empty($report->year_totals))
    <div class="mt-10">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">
            CRA Totals by Tax Year
        </h3>

        <table class="w-full text-sm">
            <thead class="border-b text-gray-500">
            <tr>
                <th class="text-left">Tax Year</th>
                <th class="text-right">Proceeds</th>
                <th class="text-right">ACB</th>
                <th class="text-right">Expenses</th>
                <th class="text-right">Gain / Loss</th>
                <th class="text-right">Taxable</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($report->year_totals as $total)
                <tr class="border-b last:border-0">
                    <td>{{ $total->tax_year }}</td>
                    <td class="text-right">{{ $total->proceeds_of_disposition->format() }}</td>
                    <td class="text-right">{{ $total->adjusted_cost_base->format() }}</td>
                    <td class="text-right">{{ $total->outlays_and_expenses->format() }}</td>
                    <td class="text-right">{{ $total->capital_gain_loss->format() }}</td>
                    <td class="text-right">{{ $total->taxable_capital_gain->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
