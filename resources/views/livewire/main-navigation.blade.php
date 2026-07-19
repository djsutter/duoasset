<div>
    @if ($variant == 'header')
        <flux:navbar class="-mb-px max-lg:hidden">
            @foreach ($menuItems as $item)
                @php
                    $icon = $item['icon'] ?? null;
                    $parameters = $item['parameters'] ?? [];
                @endphp

                <flux:navbar.item
                    :icon="$icon"
                    :href="route($item['route'], $parameters)"
                    :current="request()->routeIs($item['route'])"
                    wire:navigate
                >
                    {{ $item['title'] }}
                </flux:navbar.item>
            @endforeach
        </flux:navbar>
    @elseif ($variant == 'sidebar')
        <flux:navlist variant="outline">
            @foreach ($menuItems as $item)
                @php
                    $icon = $item['icon'] ?? null;
                    $parameters = $item['parameters'] ?? [];
                @endphp

                <flux:navbar.item
                    :icon="$icon"
                    :href="route($item['route'], $parameters)"
                    :current="request()->routeIs($item['route'])"
                    wire:navigate
                >
                    {{ $item['title'] }}
                </flux:navbar.item>
            @endforeach
        </flux:navlist>
    @endif
</div>
