@props(['type' => '', 'locked' => false])

@php
[$icon, $color] = match($type) {
    'trade' => ['arrows-right-left', 'text-blue-600'],
    'receive' => ['arrow-down', 'text-green-700'],
    'send' => ['arrow-up', 'text-red-600'],
    'transfer' => ['chevron-double-right', 'text-slate-400'],
    default => [null, null]
};

$class = '';
$class .= $locked ? ' cursor-not-allowed' : '';
$class .= $this->tx_type?->value == $type ? ' bg-blue-100 dark:bg-zinc-800' : '';
@endphp
<button
    type="button"
    class="text-xl rounded-lg border-1 border-zinc-200 p-4 {{ $class }}"
    @if (! $locked) wire:click="setTransactionType('{{ $type }}')" @endif
>
    @if ($icon)
        <flux:icon name="{{ $icon }}" variant="micro" class="size-6 inline {{ $color }}"/>
    @endif
    {{ $slot }}
</button>
