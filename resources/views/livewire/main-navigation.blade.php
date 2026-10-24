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

            @if ($canToggleViewMode)
                <div class="ms-auto flex items-center lg:hidden">
                    <button
                        type="button"
                        wire:click="toggleViewMode"
                        class="text-xs px-2 py-1 text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                        aria-label="{{ __('Toggle Perspective') }}"
                    >
                        <flux:icon icon="chevrons-up-down" variant="mini" class="size-3.5" />
                    </button>
                </div>
            @endif
        </flux:navbar>
    @elseif ($variant == 'sidebar')
        <flux:navlist variant="outline">
            @if ($isGuest)
                <div class="px-2 py-2 mb-2 border-b border-zinc-200 dark:border-zinc-800">
                    <a
                        href="{{ route('subscription') }}"
                        class="flex w-full items-center justify-center gap-2 rounded-md bg-amber-500 hover:bg-amber-600 px-3 py-2 text-xs font-semibold text-white shadow-xs transition"
                        role="button"
                        aria-label="{{ __('Subscribe to DuoAsset') }}"
                        data-testid="sidebar-guest-subscribe-button"
                        wire:navigate
                    >
                        <flux:icon icon="sparkles" variant="mini" class="size-4" />
                        <span>{{ __('Subscribe') }}</span>
                    </a>
                </div>
            @endif

            @can('admin')
                <div class="px-2 py-2 mb-2 border-b border-zinc-200 dark:border-zinc-800">
                    <div class="mb-1.5 text-xs font-medium uppercase tracking-wider text-purple-600 dark:text-purple-400">{{ __('Administration') }}</div>
                    <div class="space-y-1">
                        <a
                            href="{{ route('admin.config') }}"
                            class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-xs font-semibold text-zinc-700 hover:text-zinc-900 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 transition"
                            wire:navigate
                            data-testid="sidebar-admin-config-link"
                        >
                            <flux:icon icon="wrench-screwdriver" variant="mini" class="size-4 text-purple-600 dark:text-purple-400" />
                            <span>{{ __('Admin Configuration') }}</span>
                        </a>
                        <a
                            href="{{ route('admin.accounts') }}"
                            class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-xs font-semibold text-zinc-700 hover:text-zinc-900 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:text-white dark:hover:bg-zinc-800 transition"
                            wire:navigate
                            data-testid="sidebar-manage-accounts-link"
                        >
                            <flux:icon icon="users" variant="mini" class="size-4 text-purple-600 dark:text-purple-400" />
                            <span>{{ __('Manage Accounts') }}</span>
                        </a>
                    </div>
                </div>
            @endcan

            @if ($canToggleViewMode)
                <div class="px-2 py-2 mb-2 border-b border-zinc-200 dark:border-zinc-800">
                    <div class="mb-1.5 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Perspective') }}</div>
                    <div
                        class="inline-flex w-full rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700"
                        role="group"
                        aria-label="{{ __('Perspective Switcher') }}"
                    >
                        <button
                            type="button"
                            wire:click="setViewMode('stocks')"
                            class="flex-1 inline-flex justify-center items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all {{ $viewMode === 'stocks' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                            aria-pressed="{{ $viewMode === 'stocks' ? 'true' : 'false' }}"
                            data-testid="sidebar-view-mode-stocks"
                        >
                            <flux:icon icon="chart-bar" variant="mini" class="size-3.5" />
                            <span>{{ __('Stocks') }}</span>
                        </button>

                        <button
                            type="button"
                            wire:click="setViewMode('crypto')"
                            class="flex-1 inline-flex justify-center items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all {{ $viewMode === 'crypto' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                            aria-pressed="{{ $viewMode === 'crypto' ? 'true' : 'false' }}"
                            data-testid="sidebar-view-mode-crypto"
                        >
                            <flux:icon icon="currency-dollar" variant="mini" class="size-3.5" />
                            <span>{{ __('Crypto') }}</span>
                        </button>
                    </div>
                </div>
            @endif

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
