@props(['symbol', 'class' => ''])

@php
    $cleanSymbol = trim((string) $symbol);
@endphp

@if ($cleanSymbol !== '' && $cleanSymbol !== '—')
    <a href="{{ route('stocks.show', ['symbol' => $cleanSymbol]) }}"
       wire:navigate
       x-on:click.stop
       class="inline-flex items-center gap-1 font-mono font-semibold text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300 hover:underline transition-colors group {{ $class }}"
       title="{{ __('View stock analysis and chart for :symbol', ['symbol' => $cleanSymbol]) }}">
        <span>{{ $cleanSymbol }}</span>
        <svg class="size-3 opacity-0 group-hover:opacity-100 transition-opacity text-sky-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
        </svg>
    </a>
@else
    <span class="text-zinc-400 dark:text-zinc-600">—</span>
@endif
