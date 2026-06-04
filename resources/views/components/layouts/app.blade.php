<x-layouts.app.header :title="$title ?? null">
    <x-notifications />
    <flux:main class="w-2/3 mx-auto">
        {{ $slot }}
    </flux:main>
</x-layouts.app.header>
