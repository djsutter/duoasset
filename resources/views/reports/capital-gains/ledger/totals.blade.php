@if (!empty($report->asset_totals))
    <div class="mt-8">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">
            Totals by Asset
        </h3>

        <table class="w-full text-sm border-collapse">
            <thead class="border-b text-gray-500">
            <tr>
                <th class="text-left py-1">Asset</th>
                <th class="text-right py-1">Proceeds</th>
                <th class="text-right py-1">ACB</th>
                <th class="text-right py-1">Gain / Loss</th>
                <th class="text-right py-1">Taxable</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($report->asset_totals as $total)
                <tr class="border-b last:border-0">
                    <td class="py-1">
                        {{ $total->asset_code }}
                        <span class="text-gray-400">
                                {{ $total->asset_name }}
                            </span>
                    </td>
                    <td class="text-right">{{ $total->total_proceeds->format() }}</td>
                    <td class="text-right">{{ $total->total_acb->format() }}</td>
                    <td class="text-right">{{ $total->total_gain_or_loss->format() }}</td>
                    <td class="text-right">{{ $total->taxable_amount->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@if (!empty($report->year_totals))
    <div class="mt-8">
        <h3 class="text-sm font-semibold text-gray-700 mb-2">
            Totals by Tax Year
        </h3>

        <table class="w-full text-sm border-collapse">
            <thead class="border-b text-gray-500">
            <tr>
                <th class="text-left py-1">Tax Year</th>
                <th class="text-right py-1">Proceeds</th>
                <th class="text-right py-1">ACB</th>
                <th class="text-right py-1">Gain / Loss</th>
                <th class="text-right py-1">Taxable</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($report->year_totals as $total)
                <tr class="border-b last:border-0">
                    <td class="py-1">{{ $total->tax_year }}</td>
                    <td class="text-right">{{ $total->total_proceeds->format() }}</td>
                    <td class="text-right">{{ $total->total_acb->format() }}</td>
                    <td class="text-right">{{ $total->total_gain_or_loss->format() }}</td>
                    <td class="text-right">{{ $total->taxable_amount->format() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
