<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ __('Admin Error & Activity Log Viewer') }}</h1>
                <span class="inline-flex items-center rounded-md bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900/50 dark:text-purple-300">
                    {{ __('Observability') }}
                </span>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                {{ __('Diagnostic event logging for Notifications/Email delivery, Stock Buy Setup scoring, and EPS/Earnings processing.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.setup-run-report') }}"
               class="da-btn da-btn-secondary gap-1.5"
               wire:navigate
               data-testid="nav-setup-report-btn">
                <flux:icon icon="chart-bar" variant="mini" class="size-4" />
                <span>{{ __('Setup Run Report') }}</span>
            </a>

            <a href="{{ route('admin.config') }}"
               class="da-btn da-btn-secondary gap-1.5"
               wire:navigate
               data-testid="nav-admin-config-btn">
                <flux:icon icon="wrench-screwdriver" variant="mini" class="size-4" />
                <span>{{ __('Admin Config') }}</span>
            </a>
        </div>
    </div>

    {{-- Metric Stat Cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        <div class="da-card flex flex-col justify-between cursor-pointer transition hover:border-zinc-400 dark:hover:border-zinc-700 {{ $filterType === 'all' && $filterLevel === 'all' ? 'ring-2 ring-indigo-500' : '' }}"
             wire:click="clearFilters"
             data-testid="stat-card-total-logs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('Total Logged') }}</span>
                <flux:icon icon="document-text" variant="mini" class="size-4 text-zinc-400" />
            </div>
            <div class="mt-2 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($summary['total_scanned'] ?? 0) }}</span>
                <span class="text-xs text-zinc-500">{{ __('events') }}</span>
            </div>
        </div>

        <div class="da-card flex flex-col justify-between cursor-pointer transition hover:border-purple-400 dark:hover:border-purple-700 {{ $filterType === 'notifications' ? 'ring-2 ring-purple-500' : '' }}"
             wire:click="setFilterType('notifications')"
             data-testid="stat-card-notifications-logs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-purple-600 dark:text-purple-400 uppercase tracking-wider">{{ __('Notifications / Email') }}</span>
                <flux:icon icon="envelope" variant="mini" class="size-4 text-purple-500" />
            </div>
            <div class="mt-2 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-purple-600 dark:text-purple-400">{{ number_format($summary['notifications'] ?? 0) }}</span>
                <span class="text-xs text-zinc-500">{{ __('alerts / mail') }}</span>
            </div>
        </div>

        <div class="da-card flex flex-col justify-between cursor-pointer transition hover:border-emerald-400 dark:hover:border-emerald-700 {{ $filterType === 'setup' ? 'ring-2 ring-emerald-500' : '' }}"
             wire:click="setFilterType('setup')"
             data-testid="stat-card-setup-logs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">{{ __('Setup Processing') }}</span>
                <flux:icon icon="sparkles" variant="mini" class="size-4 text-emerald-500" />
            </div>
            <div class="mt-2 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($summary['setup'] ?? 0) }}</span>
                <span class="text-xs text-zinc-500">{{ __('scores / rejects') }}</span>
            </div>
        </div>

        <div class="da-card flex flex-col justify-between cursor-pointer transition hover:border-amber-400 dark:hover:border-amber-700 {{ $filterType === 'eps' ? 'ring-2 ring-amber-500' : '' }}"
             wire:click="setFilterType('eps')"
             data-testid="stat-card-eps-logs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('EPS Processing') }}</span>
                <flux:icon icon="chart-bar" variant="mini" class="size-4 text-amber-500" />
            </div>
            <div class="mt-2 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ number_format($summary['eps'] ?? 0) }}</span>
                <span class="text-xs text-zinc-500">{{ __('events / surprises') }}</span>
            </div>
        </div>

        <div class="da-card flex flex-col justify-between cursor-pointer transition hover:border-rose-400 dark:hover:border-rose-700 {{ $filterLevel === 'error' ? 'ring-2 ring-rose-500' : '' }}"
             wire:click="setFilterLevel('error')"
             data-testid="stat-card-error-logs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">{{ __('Errors / Failures') }}</span>
                <flux:icon icon="exclamation-circle" variant="mini" class="size-4 text-rose-500" />
            </div>
            <div class="mt-2 flex items-baseline justify-between">
                <span class="text-2xl font-bold text-rose-600 dark:text-rose-400">{{ number_format($summary['errors'] ?? 0) }}</span>
                <span class="text-xs text-zinc-500">{{ __('require attention') }}</span>
            </div>
        </div>
    </div>

    {{-- Filters & Search Toolbar --}}
    <div class="da-card space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            {{-- Category Filter Pills --}}
            <div class="flex flex-wrap items-center gap-1.5" data-testid="filter-type-group">
                <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 me-1">{{ __('Filter by Type:') }}</span>
                @foreach ($typeLabels as $typeKey => $typeLabel)
                    <button type="button"
                            wire:click="setFilterType('{{ $typeKey }}')"
                            class="px-2.5 py-1 text-xs font-medium rounded-full transition {{ $filterType === $typeKey ? 'bg-indigo-600 text-white shadow-xs' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
                            data-testid="filter-type-{{ $typeKey }}">
                        {{ $typeLabel }}
                    </button>
                @endforeach
            </div>

            {{-- Level Filter Pills --}}
            <div class="flex flex-wrap items-center gap-1.5" data-testid="filter-level-group">
                <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 me-1">{{ __('Level:') }}</span>
                @foreach ($levelLabels as $levelKey => $levelLabel)
                    <button type="button"
                            wire:click="setFilterLevel('{{ $levelKey }}')"
                            class="px-2.5 py-1 text-xs font-medium rounded-full transition {{ $filterLevel === $levelKey ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
                            data-testid="filter-level-{{ $levelKey }}">
                        {{ $levelLabel }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-3 border-t border-zinc-200 dark:border-zinc-800">
            {{-- Search Box --}}
            <div class="relative flex-1 max-w-md">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    placeholder="{{ __('Search by symbol, email, reason, or error...') }}"
                    data-testid="search-logs-input"
                />
            </div>

            <div class="flex items-center gap-2">
                @if ($filterType !== 'all' || $filterLevel !== 'all' || $search !== '')
                    <button type="button"
                            wire:click="clearFilters"
                            class="da-btn da-btn-secondary text-xs"
                            data-testid="clear-filters-btn">
                        <flux:icon icon="x-mark" variant="mini" class="size-3.5" />
                        <span>{{ __('Reset Filters') }}</span>
                    </button>
                @endif

                <button type="button"
                        wire:click="$refresh"
                        class="da-btn da-btn-secondary text-xs"
                        data-testid="refresh-logs-btn">
                    <flux:icon icon="arrow-path" variant="mini" class="size-3.5" />
                    <span>{{ __('Refresh Logs') }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Log Entries Table --}}
    <div class="da-card overflow-hidden p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-start text-xs" data-testid="log-entries-table">
                <thead class="bg-zinc-50 dark:bg-zinc-900/60 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 uppercase tracking-wider font-semibold">
                    <tr>
                        <th class="py-3 px-4 text-start">{{ __('Timestamp') }}</th>
                        <th class="py-3 px-3 text-start">{{ __('Level') }}</th>
                        <th class="py-3 px-3 text-start">{{ __('Category') }}</th>
                        <th class="py-3 px-3 text-start">{{ __('Symbol') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('Event / Diagnostic Message') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('Key Details / Reason') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($entries as $index => $entry)
                        @php
                            $level = strtolower($entry['level'] ?? 'info');
                            $levelBadgeClass = match ($level) {
                                'error', 'critical', 'alert', 'emergency' => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300 font-semibold',
                                'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300 font-semibold',
                                default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                            };

                            $type = $entry['type'] ?? 'system';
                            $typeBadgeClass = match ($type) {
                                'notifications' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-300',
                                'setup' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300',
                                'eps' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300',
                                default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
                            };

                            $context = $entry['context'] ?? [];
                            $reason = $context['reason'] ?? $context['msg'] ?? $context['error'] ?? null;
                            if (is_array($reason)) {
                                $reason = json_encode($reason);
                            }
                        @endphp
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition" data-testid="log-row-{{ $index }}">
                            {{-- Timestamp --}}
                            <td class="py-3 px-4 font-mono text-zinc-600 dark:text-zinc-400 whitespace-nowrap">
                                {{ $entry['timestamp'] }}
                            </td>

                            {{-- Level --}}
                            <td class="py-3 px-3 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded text-[11px] uppercase {{ $levelBadgeClass }}">
                                    {{ $entry['level'] }}
                                </span>
                            </td>

                            {{-- Category --}}
                            <td class="py-3 px-3 whitespace-nowrap">
                                <span class="px-2 py-0.5 rounded text-[11px] font-medium {{ $typeBadgeClass }}">
                                    {{ $entry['type_label'] }}
                                </span>
                            </td>

                            {{-- Symbol --}}
                            <td class="py-3 px-3 whitespace-nowrap">
                                @if (! empty($entry['symbol']))
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded font-mono font-bold bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300">
                                        {{ $entry['symbol'] }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>

                            {{-- Message --}}
                            <td class="py-3 px-4 max-w-xs font-mono text-xs text-zinc-900 dark:text-white truncate">
                                {{ $entry['message'] }}
                            </td>

                            {{-- Details / Reason --}}
                            <td class="py-3 px-4 max-w-sm text-zinc-600 dark:text-zinc-300 truncate">
                                @if ($reason)
                                    <span class="text-zinc-700 dark:text-zinc-200 font-medium">{{ $reason }}</span>
                                @elseif (! empty($context))
                                    <span class="text-zinc-500 font-mono text-[11px]">{{ Str::limit(json_encode($context), 60) }}</span>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>

                            {{-- Actions --}}
                            <td class="py-3 px-4 text-end whitespace-nowrap">
                                <button type="button"
                                        wire:click="showDetails({{ $index }})"
                                        class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-semibold"
                                        data-testid="log-view-btn-{{ $index }}">
                                    {{ __('Details') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 px-4 text-center">
                                <flux:icon icon="check-circle" class="size-8 mx-auto text-emerald-500 mb-2" />
                                <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('No log entries match the criteria') }}</div>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                                    {{ __('Try clearing active filters or check that logs have been generated.') }}
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal: Entry Details --}}
    @if ($selectedEntry !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs"
             data-testid="log-details-modal">
            <div class="da-card w-full max-w-3xl max-h-[85vh] overflow-y-auto space-y-4 shadow-xl">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-xs uppercase font-semibold {{ in_array(strtolower($selectedEntry['level']), ['error', 'critical', 'alert', 'emergency']) ? 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300' }}">
                            {{ $selectedEntry['level'] }}
                        </span>
                        <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Log Event Diagnostics') }}</h3>
                    </div>
                    <button type="button"
                            wire:click="closeDetails"
                            class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200"
                            data-testid="close-log-modal-btn">
                        <flux:icon icon="x-mark" variant="mini" class="size-5" />
                    </button>
                </div>

                <div class="space-y-3 text-xs">
                    <div class="grid grid-cols-2 gap-3 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60">
                        <div>
                            <span class="text-zinc-500 block">{{ __('Timestamp:') }}</span>
                            <span class="font-mono font-semibold text-zinc-900 dark:text-white">{{ $selectedEntry['timestamp'] }}</span>
                        </div>
                        <div>
                            <span class="text-zinc-500 block">{{ __('Category / Domain:') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ $selectedEntry['type_label'] }}</span>
                        </div>
                        <div>
                            <span class="text-zinc-500 block">{{ __('Symbol:') }}</span>
                            <span class="font-mono font-bold text-zinc-900 dark:text-white">{{ $selectedEntry['symbol'] ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-zinc-500 block">{{ __('Channel:') }}</span>
                            <span class="font-mono text-zinc-900 dark:text-white">{{ $selectedEntry['channel'] }}</span>
                        </div>
                    </div>

                    <div>
                        <span class="font-semibold text-zinc-700 dark:text-zinc-300 block mb-1">{{ __('Message / Event Identifier:') }}</span>
                        <div class="p-2.5 rounded bg-zinc-100 dark:bg-zinc-900 font-mono text-xs text-zinc-900 dark:text-zinc-100 select-all">
                            {{ $selectedEntry['message'] }}
                        </div>
                    </div>

                    @if (! empty($selectedEntry['context']))
                        <div>
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300 block mb-1">{{ __('Parsed Context Payload:') }}</span>
                            <pre class="p-3 rounded bg-zinc-900 text-zinc-100 dark:bg-zinc-950 font-mono text-[11px] overflow-x-auto select-all">{{ json_encode($selectedEntry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif

                    @if (! empty($selectedEntry['stack_trace']))
                        <div>
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300 block mb-1">{{ __('Stack Trace / Exception Trace:') }}</span>
                            <pre class="p-3 rounded bg-zinc-950 text-rose-300 font-mono text-[11px] max-h-60 overflow-y-auto select-all">{{ $selectedEntry['stack_trace'] }}</pre>
                        </div>
                    @endif

                    <div>
                        <span class="font-semibold text-zinc-700 dark:text-zinc-300 block mb-1">{{ __('Raw Monolog Record:') }}</span>
                        <div class="p-2 rounded bg-zinc-100 dark:bg-zinc-900/80 font-mono text-[11px] text-zinc-600 dark:text-zinc-400 break-all select-all">
                            {{ $selectedEntry['raw'] }}
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button"
                            wire:click="closeDetails"
                            class="da-btn da-btn-secondary"
                            data-testid="modal-close-btn">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
