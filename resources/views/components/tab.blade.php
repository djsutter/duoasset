@props([
    'selected' => false,
])

<div
    class="inline font-bold border-b border-gray-300 dark:border-gray-600 data-selected:border-b-2 data-selected:border-gray-900 p-4 dark:data-selected:border-gray-100 cursor-pointer"
    x-bind:data-selected="{{ $selected }}"
>
    {{ $slot }}
</div>
