@php
    /** @var \App\Data\Reports\CraCapitalGainsDispositionData $row */
@endphp

<table class="w-full text-sm">
    <thead>
    <tr>
        <th class="text-left">Date of disposition</th>
        <th class="text-right">Proceeds of disposition</th>
        <th class="text-right">Adjusted cost base</th>
        <th class="text-right">Outlays and expenses</th>
        <th class="text-right">Capital gain (loss)</th>
    </tr>
    </thead>

    <tbody>
    @foreach ($dispositions as $row)
        <tr>
            <td class="text-left">{{ $row->disposed_at }}</td>
            <td class="text-right">@money($row->proceeds_of_disposition)</td>
            <td class="text-right">@money($row->adjusted_cost_base)</td>
            <td class="text-right">@money($row->outlays_and_expenses)</td>
            <td class="text-right">@money($row->capital_gain_loss)</td>
        </tr>
    @endforeach
    </tbody>
</table>
