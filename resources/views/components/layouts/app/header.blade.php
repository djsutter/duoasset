<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800 font-[Nunito,sans-serif]">
        <flux:header container class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <a href="{{ route('dashboard') }}" class="ms-2 me-5 flex items-center space-x-2 rtl:space-x-reverse lg:ms-0" wire:navigate>
                <div x-show="$flux.dark">
                    <img src="/images/DuoAssetLogoDark.png" width="120" />
                </div>
                <div x-show="! $flux.dark">
                    <img src="/images/DuoAssetLogoLight.png" width="120" />
                </div>
            </a>

            <livewire:main-navigation variant="header" />

            <flux:spacer />

            @can('toggle-view-mode')
                <div class="me-2 flex items-center">
                    <livewire:view-mode-toggle />
                </div>
            @endcan

            @can('admin')
                <div class="me-2 flex items-center gap-1.5">
                    <a
                        href="{{ route('admin.config') }}"
                        class="inline-flex items-center gap-1.5 rounded-md bg-purple-50 hover:bg-purple-100 dark:bg-purple-950/50 dark:hover:bg-purple-900/60 border border-purple-200 dark:border-purple-800 px-2.5 py-1.5 text-xs font-semibold text-purple-700 dark:text-purple-300 shadow-xs transition"
                        role="button"
                        aria-label="{{ __('Admin Configuration') }}"
                        data-testid="header-admin-config-button"
                        wire:navigate
                    >
                        <flux:icon icon="wrench-screwdriver" variant="mini" class="size-4" />
                        <span>{{ __('Admin Config') }}</span>
                    </a>

                    <a
                        href="{{ route('admin.accounts') }}"
                        class="inline-flex items-center gap-1.5 rounded-md bg-purple-50 hover:bg-purple-100 dark:bg-purple-950/50 dark:hover:bg-purple-900/60 border border-purple-200 dark:border-purple-800 px-2.5 py-1.5 text-xs font-semibold text-purple-700 dark:text-purple-300 shadow-xs transition"
                        role="button"
                        aria-label="{{ __('Manage Accounts') }}"
                        data-testid="header-manage-accounts-button"
                        wire:navigate
                    >
                        <flux:icon icon="users" variant="mini" class="size-4" />
                        <span>{{ __('Manage Accounts') }}</span>
                    </a>
                </div>
            @endcan

            @if (auth()->user()?->isGuest())
                <div class="me-2 flex items-center">
                    <a
                        href="{{ route('subscription') }}"
                        class="inline-flex items-center gap-1.5 rounded-md bg-amber-500 hover:bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 transition"
                        role="button"
                        aria-label="{{ __('Subscribe to DuoAsset') }}"
                        data-testid="header-guest-subscribe-button"
                        wire:navigate
                    >
                        <flux:icon icon="sparkles" variant="mini" class="size-4" />
                        <span>{{ __('Subscribe') }}</span>
                    </a>
                </div>
            @endif

            <flux:navbar class="me-1.5 space-x-0.5 rtl:space-x-reverse py-0!">
                <flux:tooltip :content="__('Search')" position="bottom">
                    <flux:navbar.item class="!h-10 [&>div>svg]:size-5" icon="magnifying-glass" href="#" :label="__('Search')" />
                </flux:tooltip>
            </flux:navbar>

            <div x-data x-on:click="$flux.dark = ! $flux.dark" class="cursor-pointer">
                <flux:icon.sun x-cloak x-show="$flux.appearance !== 'dark'" variant="mini" class="text-zinc-500 dark:text-white" />
                <flux:icon.moon x-cloak x-show="$flux.appearance === 'dark'" variant="mini" class="text-zinc-500 dark:text-white" />
                <div class="w-[30px]"></div>
            </div>

            <!-- Desktop User Menu -->
            <flux:dropdown position="top" align="end">
                <flux:profile
                    class="cursor-pointer"
                    :initials="auth()->user()->initials()"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    @can('admin')
                        <flux:menu.separator />
                        <flux:menu.radio.group>
                            <flux:menu.item :href="route('admin.config')" icon="wrench-screwdriver" wire:navigate data-testid="dropdown-admin-config-link">{{ __('Admin Configuration') }}</flux:menu.item>
                            <flux:menu.item :href="route('admin.accounts')" icon="users" wire:navigate data-testid="dropdown-manage-accounts-link">{{ __('Manage Accounts') }}</flux:menu.item>
                        </flux:menu.radio.group>
                    @endcan

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <!-- Mobile Menu -->
        <flux:sidebar stashable sticky class="lg:hidden border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ route('dashboard') }}" class="ms-1 flex items-center space-x-2 rtl:space-x-reverse" wire:navigate>
                <img src="/images/DuoAssetLogo.png" width="120" />
            </a>

            <livewire:main-navigation variant="sidebar" />

            <flux:spacer />
        </flux:sidebar>

        {{ $slot }}
        <div></div>
        <div></div>
        <div></div>
        <x-footer class="w-full" />

        @fluxScripts
    </body>
</html>
