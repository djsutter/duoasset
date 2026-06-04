@php
    /** @var \App\Data\Reports\LedgerCapitalGainsDispositionData $row */
@endphp

<table class="w-full text-sm">
    <thead>
    <tr>
        <th class="text-left">Date of disposition</th>
        <th class="text-right">Proceeds</th>
        <th class="text-right">Adjusted cost base</th>
        <th class="text-right">Expenses</th>
        <th class="text-right">Gain / Loss</th>
    </tr>
    </thead>

    <tbody>
    @foreach ($dispositions as $row)
        <tr>
            <td class="text-left">{{ $row->disposed_at }}</td>
            <td class="text-right">@money($row->proceeds)</td>
            <td class="text-right">@money($row->acb_allocated)</td>
            <td class="text-right">@money($row->expenses)</td>
            <td class="text-right">@money($row->gain_or_loss)</td>
        </tr>
    @endforeach
    </tbody>
</table>
