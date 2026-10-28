<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ __('Stock Buy Setup Run Dashboard') }}</h1>
                <span class="inline-flex items-center rounded-md bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900/50 dark:text-purple-300">
                    {{ __('Setup Scoring Analytics') }}
                </span>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                {{ __('Observability for the latest stock screener run: companies considered, scored, matches, and categorized rejections.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.logs') }}"
               class="da-btn da-btn-secondary gap-1.5"
               wire:navigate
               data-testid="nav-error-logs-btn">
                <flux:icon icon="document-text" variant="mini" class="size-4" />
                <span>{{ __('Error Log Viewer') }}</span>
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

    @if (! $run)
        {{-- Empty State when no scans have been recorded --}}
        <div class="da-card py-16 text-center space-y-4" data-testid="empty-setup-runs">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-indigo-50 dark:bg-indigo-950/50">
                <flux:icon icon="chart-bar" class="size-8 text-indigo-600 dark:text-indigo-400" />
            </div>
            <div class="max-w-md mx-auto space-y-2">
                <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('No Setup Scan Runs Recorded Yet') }}</h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 leading-relaxed">
                    {{ __('Run a stock buy setup scan from the command line to populate this dashboard with real execution statistics, scored symbols, and categorized rejection reasons.') }}
                </p>
            </div>
            <div class="pt-2">
                <code class="px-3 py-1.5 rounded bg-zinc-100 dark:bg-zinc-800 text-xs font-mono text-indigo-600 dark:text-indigo-400 font-semibold select-all">
                    php artisan stocks:scan-buy-setups --sync
                </code>
            </div>
        </div>
    @else
        {{-- Active Run Overview Banner --}}
        <div class="da-card space-y-4" data-testid="active-run-banner">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-zinc-200 dark:border-zinc-800 pb-3">
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-1.5">
                        <span class="text-base font-bold text-zinc-900 dark:text-white">
                            {{ __('Run #') }}{{ $run->id }}
                        </span>
                        @if ($recentRuns->first()?->id === $run->id)
                            <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                                {{ __('Latest / Most Recent') }}
                            </span>
                        @endif
                    </div>

                    @php
                        $statusBadgeClass = match ($run->status) {
                            'completed' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300',
                            'running' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300',
                            default => 'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300',
                        };
                    @endphp
                    <span class="px-2 py-0.5 rounded text-[11px] font-semibold uppercase {{ $statusBadgeClass }}" data-testid="run-status-badge">
                        {{ $run->status }}
                    </span>

                    <span class="px-2 py-0.5 rounded text-[11px] font-medium bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ $run->trigger }}
                    </span>
                </div>

                <div class="flex items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400 font-mono">
                    <span>
                        <strong class="text-zinc-700 dark:text-zinc-300">{{ __('Started:') }}</strong>
                        {{ $run->started_at?->format('Y-m-d H:i:s') ?? '—' }}
                    </span>
                    <span>
                        <strong class="text-zinc-700 dark:text-zinc-300">{{ __('Duration:') }}</strong>
                        {{ $run->formattedDuration() }}
                    </span>
                </div>
            </div>

            {{-- 5 High-Level Statistics Cards --}}
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 pt-1">
                <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800/80" data-testid="stat-considered">
                    <span class="text-[11px] font-semibold text-zinc-500 uppercase tracking-wider block">{{ __('Companies Considered') }}</span>
                    <span class="text-2xl font-bold text-zinc-900 dark:text-white mt-1 block">{{ number_format($run->symbols_considered) }}</span>
                    <span class="text-[11px] text-zinc-500">{{ __('screener universe') }}</span>
                </div>

                <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800/80" data-testid="stat-scored">
                    <span class="text-[11px] font-semibold text-sky-600 dark:text-sky-400 uppercase tracking-wider block">{{ __('Companies Scored') }}</span>
                    <span class="text-2xl font-bold text-sky-600 dark:text-sky-400 mt-1 block">{{ number_format($run->scored_count) }}</span>
                    <span class="text-[11px] text-zinc-500">
                        @if ($run->symbols_considered > 0)
                            {{ round(($run->scored_count / $run->symbols_considered) * 100, 1) }}% {{ __('of considered') }}
                        @else
                            {{ __('evaluated') }}
                        @endif
                    </span>
                </div>

                <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800/80" data-testid="stat-matched">
                    <span class="text-[11px] font-semibold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider block">{{ __('Matches Found') }}</span>
                    <span class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1 block">{{ number_format($run->matched_count) }}</span>
                    <span class="text-[11px] text-emerald-600 dark:text-emerald-400">
                        @if ($run->scored_count > 0)
                            {{ round(($run->matched_count / $run->scored_count) * 100, 1) }}% {{ __('hit rate') }}
                        @else
                            {{ __('qualifying') }}
                        @endif
                    </span>
                </div>

                <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800/80" data-testid="stat-rejected">
                    <span class="text-[11px] font-semibold text-amber-600 dark:text-amber-400 uppercase tracking-wider block">{{ __('Not Scored / Rejected') }}</span>
                    <span class="text-2xl font-bold text-amber-600 dark:text-amber-400 mt-1 block">{{ number_format($run->rejected_count) }}</span>
                    <span class="text-[11px] text-amber-600 dark:text-amber-400">
                        @if ($run->scored_count > 0)
                            {{ round(($run->rejected_count / $run->scored_count) * 100, 1) }}% {{ __('of scored') }}
                        @else
                            {{ __('unqualified') }}
                        @endif
                    </span>
                </div>

                <div class="p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200 dark:border-zinc-800/80" data-testid="stat-errors">
                    <span class="text-[11px] font-semibold text-rose-600 dark:text-rose-400 uppercase tracking-wider block">{{ __('Processing Errors') }}</span>
                    <span class="text-2xl font-bold text-rose-600 dark:text-rose-400 mt-1 block">{{ number_format($run->error_count) }}</span>
                    <span class="text-[11px] text-zinc-500">{{ __('failed / API errors') }}</span>
                </div>
            </div>
        </div>

        {{-- Categorized Rejections Breakdown --}}
        <div class="da-card space-y-4" data-testid="categorized-rejections-card">
            <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                <div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon icon="funnel" variant="mini" class="size-5 text-indigo-500" />
                        <span>{{ __('Categorized Rejection Reasons Breakdown') }}</span>
                    </h3>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                        {{ __('Why companies were filtered out or not scored (insufficient trading history, market cap minimums, technical setup gates).') }}
                    </p>
                </div>
                <div class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                    {{ number_format($run->rejected_count) }} {{ __('Total Rejections') }}
                </div>
            </div>

            <div class="space-y-3.5">
                @forelse ($breakdown as $item)
                    <div class="space-y-1.5" data-testid="rejection-category-{{ $item['key'] }}">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">
                                {{ $item['label'] }}
                            </span>
                            <div class="flex items-center gap-2 font-mono">
                                <span class="font-bold text-zinc-900 dark:text-white">{{ number_format($item['count']) }}</span>
                                <span class="text-zinc-400 text-[11px]">({{ $item['pct_of_rejected'] }}% {{ __('of rejected') }})</span>
                            </div>
                        </div>

                        @php
                            $barColor = match ($item['key']) {
                                'insufficient_history' => 'bg-amber-500',
                                'below_min_market_cap' => 'bg-sky-500',
                                'above_max_market_cap' => 'bg-indigo-500',
                                'exchange_mismatch' => 'bg-purple-500',
                                'technical_criteria_unmet' => 'bg-zinc-500',
                                'execution_error' => 'bg-rose-500',
                                default => 'bg-zinc-400',
                            };
                        @endphp
                        <div class="w-full bg-zinc-100 dark:bg-zinc-800 h-2.5 rounded-full overflow-hidden">
                            <div class="{{ $barColor }} h-2.5 rounded-full transition-all duration-500"
                                 style="width: {{ min(100, max(0, $item['pct_of_rejected'])) }}%"></div>
                        </div>
                    </div>
                @empty
                    <div class="py-4 text-center text-xs text-zinc-500">
                        {{ __('No rejections were recorded in this scan run.') }}
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Details Grid: Top Specific Rejection Reasons & Top Matched Setups --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Top Specific Rejection Reasons --}}
            <div class="da-card space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon icon="list-bullet" variant="mini" class="size-4 text-zinc-500" />
                        <span>{{ __('Specific Rejection Reasons') }}</span>
                    </h3>
                </div>

                @if (! empty($run->raw_reasons))
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-800 text-xs">
                        @php
                            $reasons = $run->raw_reasons;
                            arsort($reasons);
                        @endphp
                        @foreach (array_slice($reasons, 0, 15, true) as $reasonText => $count)
                            <div class="py-2.5 flex items-start justify-between gap-3">
                                <span class="text-zinc-700 dark:text-zinc-300 font-mono text-[11px]">
                                    {{ $reasonText }}
                                </span>
                                <span class="px-2 py-0.5 rounded font-bold font-mono text-xs bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200 shrink-0">
                                    {{ number_format($count) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-6 text-center text-xs text-zinc-500">
                        {{ __('No detailed reason strings stored.') }}
                    </div>
                @endif
            </div>

            {{-- Top Matches Table --}}
            <div class="da-card space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon icon="sparkles" variant="mini" class="size-4 text-emerald-500" />
                        <span>{{ __('Top Matched Setups') }}</span>
                    </h3>
                    <span class="text-xs text-zinc-500 font-mono">{{ count($run->top_matches ?? []) }} {{ __('recorded') }}</span>
                </div>

                @if (! empty($run->top_matches))
                    <div class="overflow-x-auto">
                        <table class="w-full text-start text-xs">
                            <thead class="text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th class="py-2 text-start">{{ __('Symbol') }}</th>
                                    <th class="py-2 text-start">{{ __('Setup Type') }}</th>
                                    <th class="py-2 text-center">{{ __('Score') }}</th>
                                    <th class="py-2 text-end">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60 font-mono">
                                @foreach ($run->top_matches as $m)
                                    <tr>
                                        <td class="py-2 font-bold text-sky-600 dark:text-sky-400">
                                            @if (! empty($m['symbol']))
                                                <x-ticker-link :symbol="$m['symbol']" />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2 text-zinc-600 dark:text-zinc-300">{{ $m['setup_type'] ?? '—' }}</td>
                                        <td class="py-2 text-center">
                                            <span class="px-1.5 py-0.5 rounded text-[11px] font-bold {{ ($m['score'] ?? 0) >= 70 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200' }}">
                                                {{ $m['score'] ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="py-2 text-end text-zinc-500">{{ $m['status'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="py-6 text-center text-xs text-zinc-500">
                        {{ __('No setups passed all scoring gates in this run.') }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Past Setup Scan Runs Ledger --}}
        @if ($recentRuns->count() > 1)
            <div class="da-card space-y-4" data-testid="recent-runs-ledger">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon icon="clock" variant="mini" class="size-4 text-zinc-500" />
                        <span>{{ __('Recent Setup Scan Runs History') }}</span>
                    </h3>
                    <span class="text-xs text-zinc-500">{{ __('Select a past run to view its report') }}</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-start text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-900/60 text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                            <tr>
                                <th class="py-2.5 px-3 text-start">{{ __('Run ID') }}</th>
                                <th class="py-2.5 px-3 text-start">{{ __('Started At') }}</th>
                                <th class="py-2.5 px-3 text-center">{{ __('Duration') }}</th>
                                <th class="py-2.5 px-3 text-center">{{ __('Scored') }}</th>
                                <th class="py-2.5 px-3 text-center">{{ __('Matched') }}</th>
                                <th class="py-2.5 px-3 text-center">{{ __('Rejected') }}</th>
                                <th class="py-2.5 px-3 text-center">{{ __('Status') }}</th>
                                <th class="py-2.5 px-3 text-end">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800 font-mono">
                            @foreach ($recentRuns as $r)
                                <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40 transition {{ $r->id === $run->id ? 'bg-indigo-50/50 dark:bg-indigo-950/20' : '' }}"
                                    data-testid="history-run-row-{{ $r->id }}">
                                    <td class="py-2.5 px-3 font-bold text-zinc-900 dark:text-white">#{{ $r->id }}</td>
                                    <td class="py-2.5 px-3 text-zinc-600 dark:text-zinc-400">{{ $r->started_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                    <td class="py-2.5 px-3 text-center text-zinc-600 dark:text-zinc-400">{{ $r->formattedDuration() }}</td>
                                    <td class="py-2.5 px-3 text-center font-semibold text-zinc-900 dark:text-white">{{ number_format($r->scored_count) }}</td>
                                    <td class="py-2.5 px-3 text-center font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($r->matched_count) }}</td>
                                    <td class="py-2.5 px-3 text-center font-semibold text-amber-600 dark:text-amber-400">{{ number_format($r->rejected_count) }}</td>
                                    <td class="py-2.5 px-3 text-center">
                                        <span class="px-2 py-0.5 rounded text-[10px] uppercase font-bold {{ $r->status === 'completed' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300' }}">
                                            {{ $r->status }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 text-end">
                                        @if ($r->id === $run->id)
                                            <span class="text-zinc-400 text-xs">{{ __('Viewing') }}</span>
                                        @else
                                            <button type="button"
                                                    wire:click="selectRun({{ $r->id }})"
                                                    class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-semibold"
                                                    data-testid="select-run-btn-{{ $r->id }}">
                                                {{ __('View') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
