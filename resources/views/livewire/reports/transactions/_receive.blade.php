<div>
    <td class="px-2 py-4">
        <div class="text-xs">{{ $transaction->dateHuman() }}</div>
        <div>
            <flux:icon name="arrow-down" variant="micro" class="size-6 inline text-green-600"/>
            {{ __('Receive') }}
        </div>
    </td>
    <td class="px-2 py-4">
        <div>{{ $transaction->description }}</div>
        <div class="text-xs">{{ $transaction->src_wallet ? 'From '.$transaction->src_wallet : '' }}</div>
    </td>
    <td class="px-2 py-4">
        <flux:icon name="arrow-right" variant="micro" class="size-6 inline text-slate-500"/>
    </td>
    <td class="px-2 py-4">
        <div>{{ $transaction->dst_wallet }}</div>
        @if ($transaction->dst_foreign_amount)
            <div class="{{ $transaction->dst_foreign_amount?->isPositive() ? 'text-green-600' : 'text-red-600' }}">
                {{ ($transaction->dst_foreign_amount?->isPositive() ? '+ ' : '- ').$transaction->dstForeignAmountFormatted() }}
            </div>
            <div class="text-xs">{{ $transaction->dstAmountFormatted() }}</div>
        @else
            <div class="{{ $transaction->dst_amount?->isPositive() ? 'text-green-600' : 'text-red-600' }}">
                {{ ($transaction->dst_amount?->isPositive() ? '+ ' : '- ').$transaction->dstAmountFormatted() }}
            </div>
        @endif
    </td>
</div>
