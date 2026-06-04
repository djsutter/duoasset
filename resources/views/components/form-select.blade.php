@props(['options', 'first' => null, 'name' => $attributes->whereStartsWith('wire:model')->first(), 'label' => ''])

@if ($errors->has($name))
    @php $borderColor = 'border-red-500'; @endphp
@else
    @php $borderColor = 'border-gray-300'; @endphp
@endif

<div class="mb-4">
    <label>{!! $label == '' ? '&nbsp;' : $label !!}</label>
    <select {!! $attributes->merge(['class' => "$borderColor decorated border-1 focus:border-indigo-500 focus:ring-indigo-500 dark:focus:border-sky-300 rounded-md py-2 w-full dark:bg-zinc-800"]) !!}>
        @if ($first !== null)
            @if (is_array($first))
                <option value="{{ key($first) }}">{{ current($first) }}</option>
            @else
                <option value="{{ $first }}">{{ $first }}</option>
            @endif
        @endif
        @foreach ($options as $value => $label)
            @if (is_array($label))
                <optgroup label="{{ $value }}">
                    @foreach ($label as $val => $lab)
                        <option value="{{ $val }}">{{ $lab }}</option>
                    @endforeach
                </optgroup>
            @else
                <option value="{{ $value }}">{{ $label }}</option>
            @endif
        @endforeach
    </select>
    @error($name)
    <p {{ $attributes->merge(['class' => 'text-sm text-red-600']) }}>{{ $message }}</p>
    @enderror
</div>
