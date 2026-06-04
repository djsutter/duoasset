<div class="grid grid-cols-2 gap-4 w-1/2">
    <div class="font-semibold">Total Proceeds</div>
    <div class="text-right">@money($summary->total_proceeds)</div>

    <div class="font-semibold">Total Adjusted Cost Base</div>
    <div class="text-right">@money($summary->total_acb)</div>

    <div class="font-semibold">Total Capital Gain / Loss</div>
    <div class="text-right">@money($summary->total_gain_or_loss)</div>
</div>
