@props(['data'])

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    <div>
        <div class="inline-block w-1/4">{{ $data->transaction_at->format('Y-m-d H:i') }}</div>
        <div class="inline-block w-[10%]">Buy</div>
        <div class="inline-block">{{ $data['dst_amount'].' '.$data['dst_currency'].' @ '.$data['rate'].' '.$data['src_currency'] }}</div>
        <div class="inline-block">Cost {{ $data['src_amount'].' '.$data['src_currency'] }}</div>
        <div class="inline-block">{{ $data['src_balance'].' '.$data['src_currency'] }}</div>
    </div>
</div>
