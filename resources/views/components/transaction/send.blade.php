@props(['data'])

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    <div>
        <div class="inline-block w-1/4">{{ $data->transaction_at->format('Y-m-d H:i') }}</div>
        <div class="inline-block w-[10%]">Send</div>
        <div class="inline-block">{{ $data['src_amount'].' '.$data['src_currency'] }}</div>
    </div>
</div>
