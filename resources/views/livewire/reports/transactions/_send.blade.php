<div>
    <td class="px-2 py-4">
        <div class="text-xs">{{ $transaction->dateHuman() }}</div>
        <div>
            <flux:icon name="arrow-up" variant="micro" class="size-6 inline text-red-600"/>
            {{ __('Send') }}
        </div>
    </td>
    <td class="px-2 py-4">
        <div>{{ $transaction->description }}</div>
        <div class="{{ $transaction->src_foreign_amount->isPositive() ? 'text-green-600' : 'text-red-600' }}">
            {{ ($transaction->src_foreign_amount->isPositive() ? '+ ' : '').$transaction->srcForeignAmountFormatted() }}
        </div>
    </td>
    <td class="px-2 py-4">
        <flux:icon name="arrow-right" variant="micro" class="size-6 inline text-slate-500"/>
    </td>
    <td class="px-2 py-4">
        <div>{{ ucfirst($transaction->dst_wallet) }}</div>
        <div class="text-xs">{{ $transaction->dstAmountFormatted() }}</div>
    </td>
</div>
