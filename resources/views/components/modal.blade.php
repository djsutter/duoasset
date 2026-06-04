@props(['minWidth' => 'auto', 'maxWidth' => '2xl'])

@php
$class = "relative z-10 bg-white dark:bg-zinc-700 rounded-lg shadow-lg overflow-visible max-w-$maxWidth"
@endphp

<div
    x-data="{
        open: @entangle($attributes->wire('model')),
        dispatchModalEvents(value) {
            $dispatch(value ? 'modal-opened' : 'modal-closed')
        }
    }"
    x-init="
        $watch('open', value => {
            document.body.classList.toggle('overflow-hidden', value);
            dispatchModalEvents(value);
        });
    "
    x-show="open"
    x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center"
    @keydown.escape.window="open = false"
>
    <!-- Backdrop -->
    <div
        x-show="open"
        x-transition.opacity
        class="absolute inset-0 bg-black/25"
    ></div>

    <!-- Modal Dialog -->
    <div
        x-show="open"
        x-transition
        class="{{ $class }} max-w-2xl"
    >
        <div class="absolute top-0 right-0 mt-4 mr-4" @click="open = false">
            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">
                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"></path>
            </svg>
        </div>
        <div class="p-6 space-y-4">
            {{ $slot }}
        </div>
    </div>
</div>
