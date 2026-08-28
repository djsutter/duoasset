<x-layouts.app.header :title="$title ?? null">
    <x-notifications />
    <flux:main class="w-full lg:w-2/3 lg:mx-auto">
        {{ $slot }}
    </flux:main>
</x-layouts.app.header>
