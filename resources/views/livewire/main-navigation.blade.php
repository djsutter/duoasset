<div>
    @if ($variant == 'header')
        <flux:navbar class="-mb-px max-lg:hidden">
            @foreach ($menuItems as $item)
                @php $icon = isset($item['icon']) ? $item['icon'] : null; @endphp
                <flux:navbar.item :icon="$icon" :href="route($item['route'])" :current="request()->routeIs($item['route'])" wire:navigate>
                    {{ $item['title'] }}
                </flux:navbar.item>
            @endforeach
        </flux:navbar>
    @elseif ($variant == 'sidebar')
        <flux:navlist variant="outline">
            @foreach ($menuItems as $item)
                @php $icon = isset($item['icon']) ? $item['icon'] : null; @endphp
                <flux:navbar.item :icon="$icon" :href="route($item['route'])" :current="request()->routeIs($item['route'])" wire:navigate>
                    {{ $item['title'] }}
                </flux:navbar.item>
            @endforeach
        </flux:navlist>
    @endif
</div>
