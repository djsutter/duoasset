@props([
    'type' => 'text',
    'disabled' => false,
    'name' => $attributes->whereStartsWith('wire:model')->first(),
    'label' => '',
])

@if ($errors->has($name))
    @php $borderColor = 'border-red-500'; @endphp
@else
    @php $borderColor = 'border-gray-300'; @endphp
@endif

<div class="mb-4">
    <label>{!! $label == '' ? '&nbsp;' : $label !!}</label>
    <input
        type="{{ $type }}"
        {{ $disabled ? 'disabled' : '' }}
        {!! $attributes->merge(['class' => "$borderColor border-1 focus:border-indigo-500 focus:ring-indigo-500 rounded-md p-1 w-full"]) !!}
    >
    @error($name)
    <p {{ $attributes->merge(['class' => 'text-sm text-red-600']) }}>{{ $message }}</p>
    @enderror
</div>
