<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ __('Admin Configuration') }}</h1>
                <span class="inline-flex items-center rounded-md bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900/50 dark:text-purple-300">
                    {{ __('System Administration') }}
                </span>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                {{ __('System overview, access control policies, and account management.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.accounts') }}"
               class="da-btn da-btn-primary gap-1.5"
               wire:navigate
               data-testid="nav-manage-accounts-btn">
                <flux:icon icon="users" variant="mini" class="size-4" />
                <span>{{ __('Manage Accounts') }}</span>
            </a>
        </div>
    </div>

    {{-- Tabs Navigation --}}
    <div class="border-b border-zinc-200 dark:border-zinc-800">
        <nav class="-mb-px flex space-x-6" aria-label="Tabs">
            <button type="button"
                    wire:click="setTab('overview')"
                    class="py-2.5 px-1 inline-flex items-center gap-2 border-b-2 text-sm font-medium transition {{ $tab === 'overview' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                    data-testid="tab-overview">
                <flux:icon icon="chart-bar" variant="mini" class="size-4" />
                <span>{{ __('System Overview') }}</span>
            </button>

            <button type="button"
                    wire:click="setTab('accounts')"
                    class="py-2.5 px-1 inline-flex items-center gap-2 border-b-2 text-sm font-medium transition {{ $tab === 'accounts' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                    data-testid="tab-accounts">
                <flux:icon icon="users" variant="mini" class="size-4" />
                <span>{{ __('Account Management') }}</span>
            </button>
        </nav>
    </div>

    @if ($tab === 'overview')
        {{-- Stat Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="da-card flex flex-col justify-between" data-testid="stat-card-users">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Total Accounts') }}</span>
                    <flux:icon icon="users" variant="mini" class="size-5 text-zinc-400" />
                </div>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $totalUsers }}</span>
                    <span class="text-xs text-zinc-500">{{ $guestCount }} {{ __('guests') }}</span>
                </div>
            </div>

            <div class="da-card flex flex-col justify-between" data-testid="stat-card-subscribers">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Active Subscribers') }}</span>
                    <flux:icon icon="sparkles" variant="mini" class="size-5 text-indigo-500" />
                </div>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $totalSubscribers }}</span>
                    <span class="text-xs text-zinc-500">{{ $fullSubCount }} {{ __('full') }}</span>
                </div>
            </div>

            <div class="da-card flex flex-col justify-between" data-testid="stat-card-admins">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Administrators') }}</span>
                    <flux:icon icon="shield-check" variant="mini" class="size-5 text-purple-500" />
                </div>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ $adminCount }}</span>
                    <span class="text-xs text-purple-600 dark:text-purple-400">{{ __('Admin access') }}</span>
                </div>
            </div>

            <div class="da-card flex flex-col justify-between" data-testid="stat-card-credits">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('User Dollar Credits') }}</span>
                    <flux:icon icon="currency-dollar" variant="mini" class="size-5 text-emerald-500" />
                </div>
                <div class="mt-2 flex items-baseline justify-between">
                    <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($netOutstandingCredits, 2) }}</span>
                    <span class="text-xs text-zinc-500">${{ number_format($totalCreditsAllocated, 2) }} {{ __('granted') }}</span>
                </div>
            </div>
        </div>

        {{-- Configuration Matrix & Policies --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Access Control & Gating Rules --}}
            <div class="da-card space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon icon="key" variant="mini" class="size-5 text-zinc-500" />
                        <span>{{ __('Domain Access Control Rules') }}</span>
                    </h3>
                </div>

                <div class="space-y-3 text-xs">
                    <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ __('Equities Domain') }}</div>
                            <div class="text-zinc-500 mt-0.5">{{ __('/stocks, /money-flows, /watchlists/*') }}</div>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300">
                            {{ __('Equities, Full, Admin') }}
                        </span>
                    </div>

                    <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ __('Crypto Domain') }}</div>
                            <div class="text-zinc-500 mt-0.5">{{ __('/wallets, /currencies, /platforms, /reports, /tax/*') }}</div>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                            {{ __('Crypto, Full, Admin') }}
                        </span>
                    </div>

                    <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ __('Guest Clamping Rule') }}</div>
                            <div class="text-zinc-500 mt-0.5">{{ __('Stock buy setups & EPS scoring strictly clamped to Page 4') }}</div>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                            {{ __('Page 4 Enforced') }}
                        </span>
                    </div>

                    <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 flex items-start justify-between">
                        <div>
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ __('Perspective Switching') }}</div>
                            <div class="text-zinc-500 mt-0.5">{{ __('Toggle view mode between Stocks and Crypto') }}</div>
                        </div>
                        <span class="px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300">
                            {{ __('Full & Admin Only') }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Account Management Summary & Quick Access --}}
            <div class="da-card space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon icon="user-group" variant="mini" class="size-5 text-zinc-500" />
                        <span>{{ __('Account Administration') }}</span>
                    </h3>
                    <a href="{{ route('admin.accounts') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium" wire:navigate>
                        {{ __('Open Full Console →') }}
                    </a>
                </div>

                <div class="space-y-3">
                    <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        {{ __('As an Administrator, you can provision new accounts, modify or revoke user roles, and apply direct dollar credits to any user ledger. Seeded accounts and legacy users are maintained with active administrative capabilities.') }}
                    </p>

                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 p-4 space-y-2">
                        <div class="text-xs font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Role Distribution:') }}</div>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div class="flex justify-between">
                                <span class="text-zinc-500">{{ __('Administrators:') }}</span>
                                <span class="font-semibold">{{ $adminCount }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">{{ __('Full Subscribers:') }}</span>
                                <span class="font-semibold">{{ $fullSubCount }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">{{ __('Equities Subscribers:') }}</span>
                                <span class="font-semibold">{{ $equitiesCount }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">{{ __('Crypto Subscribers:') }}</span>
                                <span class="font-semibold">{{ $cryptoCount }}</span>
                            </div>
                            <div class="flex justify-between col-span-2 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                                <span class="text-zinc-500">{{ __('Guest Accounts:') }}</span>
                                <span class="font-semibold">{{ $guestCount }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-2">
                        <button type="button"
                                wire:click="setTab('accounts')"
                                class="w-full da-btn da-btn-primary gap-1.5"
                                data-testid="config-goto-accounts-tab-btn">
                            <flux:icon icon="users" variant="mini" class="size-4" />
                            <span>{{ __('Manage Accounts in Tab') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($tab === 'accounts')
        <div data-testid="embedded-manage-accounts">
            <livewire:admin.manage-accounts />
        </div>
    @endif
</div>
