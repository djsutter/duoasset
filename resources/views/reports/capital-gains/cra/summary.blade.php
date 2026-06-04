<div class="grid grid-cols-2 gap-4 w-1/2">
    <div class="font-semibold">Proceeds of Disposition</div>
    <div class="text-right">@money($summary->proceeds_of_disposition)</div>

    <div class="font-semibold">Adjusted Cost Base</div>
    <div class="text-right">@money($summary->adjusted_cost_base)</div>

    <div class="font-semibold">Outlays and Expenses</div>
    <div class="text-right">@money($summary->outlays_and_expenses)</div>

    <div class="font-semibold">Capital Gain / Loss</div>
    <div class="text-right">@money($summary->capital_gain_loss)</div>

    <div class="font-semibold">Net Capital Gain / Loss</div>
    <div class="text-right">@money($summary->net_capital_gain_loss)</div>

    <div class="font-semibold">Taxable Capital Gain</div>
    <div class="text-right">@money($summary->taxable_capital_gain)</div>
</div>
