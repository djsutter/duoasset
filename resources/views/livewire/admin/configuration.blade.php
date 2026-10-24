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

        <div class="flex flex-wrap items-center gap-2">
            <button type="button"
                    wire:click="sendTestEmail"
                    wire:loading.attr="disabled"
                    class="da-btn da-btn-secondary gap-1.5"
                    data-testid="send-test-email-btn">
                <span wire:loading.remove wire:target="sendTestEmail">
                    <flux:icon icon="envelope" variant="mini" class="size-4 text-purple-600 dark:text-purple-400" />
                </span>
                <span wire:loading wire:target="sendTestEmail" class="inline-flex items-center">
                    <svg class="animate-spin size-4 text-purple-600 dark:text-purple-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                </span>
                <span wire:loading.remove wire:target="sendTestEmail">{{ __('Send Test Email') }}</span>
                <span wire:loading wire:target="sendTestEmail">{{ __('Sending...') }}</span>
            </button>

            <a href="{{ route('admin.logs') }}"
               class="da-btn da-btn-secondary gap-1.5"
               wire:navigate
               data-testid="nav-error-logs-btn">
                <flux:icon icon="document-text" variant="mini" class="size-4" />
                <span>{{ __('Error Logs') }}</span>
            </a>

            <a href="{{ route('admin.setup-run-report') }}"
               class="da-btn da-btn-secondary gap-1.5"
               wire:navigate
               data-testid="nav-setup-report-btn">
                <flux:icon icon="chart-bar" variant="mini" class="size-4" />
                <span>{{ __('Setup Run Report') }}</span>
            </a>

            <a href="{{ route('admin.accounts') }}"
               class="da-btn da-btn-primary gap-1.5"
               wire:navigate
               data-testid="nav-manage-accounts-btn">
                <flux:icon icon="users" variant="mini" class="size-4" />
                <span>{{ __('Manage Accounts') }}</span>
            </a>
        </div>
    </div>

    {{-- Test Email Status & Error Alerts --}}
    @if ($testEmailStatus)
        <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-300 shadow-xs flex items-center justify-between"
             data-testid="admin-test-email-status" role="alert">
            <div class="flex items-center gap-2">
                <flux:icon icon="check-circle" variant="mini" class="size-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
                <span>{{ $testEmailStatus }}</span>
            </div>
            <button type="button"
                    wire:click="$set('testEmailStatus', null)"
                    class="text-emerald-600 hover:text-emerald-800 dark:text-emerald-400 p-1"
                    data-testid="dismiss-test-email-status-btn"
                    aria-label="{{ __('Dismiss') }}">
                <flux:icon icon="x-mark" variant="mini" class="size-4" />
            </button>
        </div>
    @endif

    @if ($testEmailError)
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/60 dark:bg-red-950/40 dark:text-red-300 shadow-xs flex items-center justify-between"
             data-testid="admin-test-email-error" role="alert">
            <div class="flex items-center gap-2">
                <flux:icon icon="exclamation-circle" variant="mini" class="size-5 text-red-600 dark:text-red-400 shrink-0" />
                <span>{{ $testEmailError }}</span>
            </div>
            <button type="button"
                    wire:click="$set('testEmailError', null)"
                    class="text-red-600 hover:text-red-800 dark:text-red-400 p-1"
                    data-testid="dismiss-test-email-error-btn"
                    aria-label="{{ __('Dismiss') }}">
                <flux:icon icon="x-mark" variant="mini" class="size-4" />
            </button>
        </div>
    @endif

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

            <button type="button"
                    wire:click="setTab('logs')"
                    class="py-2.5 px-1 inline-flex items-center gap-2 border-b-2 text-sm font-medium transition {{ $tab === 'logs' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                    data-testid="tab-logs">
                <flux:icon icon="document-text" variant="mini" class="size-4" />
                <span>{{ __('Error Log Viewer') }}</span>
            </button>

            <button type="button"
                    wire:click="setTab('setup-run')"
                    class="py-2.5 px-1 inline-flex items-center gap-2 border-b-2 text-sm font-medium transition {{ $tab === 'setup-run' ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                    data-testid="tab-setup-run">
                <flux:icon icon="sparkles" variant="mini" class="size-4" />
                <span>{{ __('Setup Run Report') }}</span>
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

        {{-- Observability & Processing Diagnostics Summary Cards --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Error Logs Quick Overview --}}
            <div class="da-card space-y-4" data-testid="overview-error-logs-card">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon icon="document-text" variant="mini" class="size-5 text-indigo-500" />
                        <span>{{ __('Application Diagnostics & Error Logs') }}</span>
                    </h3>
                    <a href="{{ route('admin.logs') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium" wire:navigate>
                        {{ __('Open Error Console →') }}
                    </a>
                </div>

                <div class="space-y-3 text-xs">
                    <p class="text-zinc-600 dark:text-zinc-400 leading-relaxed">
                        {{ __('Real-time log stream categorized by domain. Diagnose email notifications, setup scoring evaluations, and EPS surprises without server access.') }}
                    </p>

                    <div class="grid grid-cols-2 gap-2 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 font-mono">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">{{ __('Total Scanned:') }}</span>
                            <span class="font-bold text-zinc-900 dark:text-white">{{ number_format($logSummary['total_scanned'] ?? 0) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">{{ __('Errors / Failures:') }}</span>
                            <span class="font-bold text-rose-600 dark:text-rose-400">{{ number_format($logSummary['errors'] ?? 0) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">{{ __('Notifications:') }}</span>
                            <span class="font-bold text-purple-600 dark:text-purple-400">{{ number_format($logSummary['notifications'] ?? 0) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">{{ __('Setup Processing:') }}</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($logSummary['setup'] ?? 0) }}</span>
                        </div>
                    </div>

                    <div class="pt-2 flex flex-col sm:flex-row gap-2">
                        <button type="button"
                                wire:click="setTab('logs')"
                                class="flex-1 da-btn da-btn-secondary gap-1.5 justify-center"
                                data-testid="config-goto-logs-tab-btn">
                            <flux:icon icon="document-text" variant="mini" class="size-4" />
                            <span>{{ __('View Filtered Error Logs in Tab') }}</span>
                        </button>

                        <button type="button"
                                wire:click="sendTestEmail"
                                wire:loading.attr="disabled"
                                class="da-btn da-btn-secondary gap-1.5 justify-center"
                                data-testid="diagnostic-send-test-email-btn">
                            <span wire:loading.remove wire:target="sendTestEmail">
                                <flux:icon icon="envelope" variant="mini" class="size-4 text-purple-600 dark:text-purple-400" />
                            </span>
                            <span wire:loading wire:target="sendTestEmail" class="inline-flex items-center">
                                <svg class="animate-spin size-4 text-purple-600 dark:text-purple-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                            </span>
                            <span wire:loading.remove wire:target="sendTestEmail">{{ __('Send Test Email') }}</span>
                            <span wire:loading wire:target="sendTestEmail">{{ __('Sending...') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Last Setup Run Quick Overview --}}
            <div class="da-card space-y-4" data-testid="overview-setup-run-card">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon icon="sparkles" variant="mini" class="size-5 text-emerald-500" />
                        <span>{{ __('Last Setup Run Report') }}</span>
                    </h3>
                    <a href="{{ route('admin.setup-run-report') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline font-medium" wire:navigate>
                        {{ __('Open Full Report →') }}
                    </a>
                </div>

                <div class="space-y-3 text-xs">
                    @if ($lastSetupRun)
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ __('Run #') }}{{ $lastSetupRun->id }}</span>
                                <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold {{ $lastSetupRun->status === 'completed' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300' }}">
                                    {{ $lastSetupRun->status }}
                                </span>
                            </div>
                            <span class="text-zinc-500 font-mono">{{ $lastSetupRun->started_at?->diffForHumans() ?? '—' }}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 font-mono">
                            <div class="flex justify-between">
                                <span class="text-zinc-500">{{ __('Scored:') }}</span>
                                <span class="font-bold text-sky-600 dark:text-sky-400">{{ number_format($lastSetupRun->scored_count) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">{{ __('Matches:') }}</span>
                                <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($lastSetupRun->matched_count) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">{{ __('Rejected:') }}</span>
                                <span class="font-bold text-amber-600 dark:text-amber-400">{{ number_format($lastSetupRun->rejected_count) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-zinc-500">{{ __('Duration:') }}</span>
                                <span class="font-bold text-zinc-900 dark:text-white">{{ $lastSetupRun->formattedDuration() }}</span>
                            </div>
                        </div>

                        <div class="pt-2">
                            <button type="button"
                                    wire:click="setTab('setup-run')"
                                    class="w-full da-btn da-btn-secondary gap-1.5"
                                    data-testid="config-goto-setup-tab-btn">
                                <flux:icon icon="chart-bar" variant="mini" class="size-4" />
                                <span>{{ __('View Rejection Breakdown in Tab') }}</span>
                            </button>
                        </div>
                    @else
                        <p class="text-zinc-500 py-3 text-center">
                            {{ __('No stock buy setup runs recorded yet. Execute `php artisan stocks:scan-buy-setups` to generate.') }}
                        </p>
                        <div class="pt-2">
                            <a href="{{ route('admin.setup-run-report') }}"
                               class="w-full da-btn da-btn-secondary gap-1.5 justify-center"
                               wire:navigate>
                                <flux:icon icon="chart-bar" variant="mini" class="size-4" />
                                <span>{{ __('Open Setup Dashboard') }}</span>
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @elseif ($tab === 'accounts')
        <div data-testid="embedded-manage-accounts">
            <livewire:admin.manage-accounts />
        </div>
    @elseif ($tab === 'logs')
        <div data-testid="embedded-error-logs">
            <livewire:admin.error-logs />
        </div>
    @elseif ($tab === 'setup-run')
        <div data-testid="embedded-setup-run-report">
            <livewire:admin.setup-run-report />
        </div>
    @endif
</div>
