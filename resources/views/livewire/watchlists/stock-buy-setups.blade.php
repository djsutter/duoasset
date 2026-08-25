<div class="space-y-6" wire:poll.30s
     x-data="{ modalOpen: false, selected: null }"
     x-on:keydown.escape.window="modalOpen = false; $wire.closeConfigModal()">
    <style>[x-cloak] { display: none !important; }</style>

    <div class="flex items-end justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-semibold">{{ __('Stock Buy Setups') }}</h1>
                <button type="button"
                        wire:click="openConfigModal"
                        class="inline-flex items-center justify-center p-1 text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                        title="{{ __('Buy Setup Configuration') }}"
                        aria-label="{{ __('Buy Setup Configuration') }}">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.6 6.6 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                </button>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Filterable buy setup detections. Click any row for full score breakdown, fundamentals, and reason details.') }}
            </p>
        </div>
        <button type="button" wire:click="clearFilters" class="da-btn-secondary">
            {{ __('Reset filters') }}
        </button>
    </div>

    @if ($flash)
        <div class="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-700
                    dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200">
            {{ $flash }}
        </div>
    @endif

    <div class="da-card">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-9">
            <div>
                <label class="da-label">{{ __('Symbol') }}</label>
                <input type="text" wire:model.live.debounce.400ms="symbol" class="da-input uppercase" placeholder="AAPL">
            </div>
            <div>
                <label class="da-label">{{ __('Company') }}</label>
                <input type="text" wire:model.live.debounce.400ms="company" class="da-input" placeholder="Apple">
            </div>
            <div>
                <label class="da-label">{{ __('Setup type') }}</label>
                <select wire:model.live="setupType" class="da-input">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($setupTypes as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="da-label">{{ __('Min setup score') }}</label>
                <input type="number" step="1" wire:model.live.debounce.400ms="minScore" class="da-input">
            </div>
            <div>
                <label class="da-label">{{ __('Min market cap') }}</label>
                <input type="number" step="1" wire:model.live.debounce.400ms="minMarketCap" class="da-input">
            </div>
            <div>
                <label class="da-label">{{ __('Exchange') }}</label>
                <select wire:model.live="exchange" class="da-input">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($exchanges as $ex)
                        <option value="{{ $ex }}">{{ $ex }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="da-label">{{ __('Market cap category') }}</label>
                <select wire:model.live="marketCapCategory" class="da-input">
                    <option value="">{{ __('All') }}</option>
                    <option value="mega">Mega</option>
                    <option value="large">Large</option>
                    <option value="mid">Mid</option>
                    <option value="small">Small</option>
                </select>
            </div>
            <div>
                <label class="da-label">{{ __('Detected from') }}</label>
                <input type="date" wire:model.live="dateFrom" class="da-input">
            </div>
            <div>
                <label class="da-label">{{ __('Detected to') }}</label>
                <input type="date" wire:model.live="dateTo" class="da-input">
            </div>
        </div>
        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-4">
            <div>
                <label class="da-label">{{ __('Sort by') }}</label>
                <select wire:model.live="sortBy" class="da-input">
                    <option value="setup_score">{{ __('Setup score') }}</option>
                    <option value="symbol">{{ __('Symbol') }}</option>
                    <option value="company_name">{{ __('Company') }}</option>
                    <option value="heartbeat_score">{{ __('Heartbeat score') }}</option>
                    <option value="detected_at">{{ __('Detected date') }}</option>
                    <option value="spike_date">{{ __('Spike date') }}</option>
                </select>
            </div>
            <div>
                <label class="da-label">{{ __('Direction') }}</label>
                <select wire:model.live="sortDirection" class="da-input">
                    <option value="desc">{{ __('Descending') }}</option>
                    <option value="asc">{{ __('Ascending') }}</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="unwatchedOnly"> {{ __('Hide already-watched') }}
                </label>
            </div>
        </div>
    </div>

    <div class="da-card overflow-x-auto">
        <table class="da-table">
            <thead>
                <tr>
                    <th>
                        <button type="button" class="font-semibold underline-offset-4 hover:underline" wire:click="sortByColumn('symbol')">
                            {{ __('Symbol') }}
                            @if ($sortBy === 'symbol') {{ $sortDirection === 'asc' ? '▲' : '▼' }} @endif
                        </button>
                    </th>
                    <th>{{ __('Setup Type') }}</th>
                    <th class="text-right">
                        <button type="button" class="font-semibold underline-offset-4 hover:underline" wire:click="sortByColumn('setup_score')">
                            {{ __('Score') }}
                            @if ($sortBy === 'setup_score') {{ $sortDirection === 'asc' ? '▲' : '▼' }} @endif
                        </button>
                    </th>
                    <th>
                        <button type="button" class="font-semibold underline-offset-4 hover:underline" wire:click="sortByColumn('company_name')">
                            {{ __('Company') }}
                            @if ($sortBy === 'company_name') {{ $sortDirection === 'asc' ? '▲' : '▼' }} @endif
                        </button>
                    </th>
                    <th>{{ __('Exchange') }}</th>
                    <th class="text-right">{{ __('Price') }}</th>
                    <th class="text-right">{{ __('Market Cap') }}</th>
                    <th class="text-right">
                        <button type="button" class="font-semibold underline-offset-4 hover:underline" wire:click="sortByColumn('spike_date')">
                            {{ __('Spike Date') }}
                            @if ($sortBy === 'spike_date') {{ $sortDirection === 'asc' ? '▲' : '▼' }} @endif
                        </button>
                    </th>
                    <th class="text-right">{{ __('Spike Vol') }}</th>
                    <th class="text-right">{{ __('Base Days') }}</th>
                    <th class="text-right">{{ __('Range %') }}</th>
                    <th class="text-right">
                        <button type="button" class="font-semibold underline-offset-4 hover:underline" wire:click="sortByColumn('detected_at')">
                            {{ __('Detected') }}
                            @if ($sortBy === 'detected_at') {{ $sortDirection === 'asc' ? '▲' : '▼' }} @endif
                        </button>
                    </th>
                    <th class="text-right">{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($alerts as $alert)
                    @php
                        $isWatched = $watched->contains(strtoupper($alert->symbol));
                        $breakdown = $scoreBreakdowns[$alert->id] ?? [];
                        $maxPossible = array_sum(array_column($breakdown, 'max'));
                        $breakdownWithPct = array_map(function ($item) use ($maxPossible) {
                            $max = (int) ($item['max'] ?? 0);
                            $points = (int) ($item['points'] ?? 0);
                            $pct = $max > 0 ? (int) round(($points / $max) * 100) : 0;
                            return array_merge($item, ['pct' => $pct]);
                        }, array_values($breakdown));

                        $modalData = [
                            'symbol' => $alert->symbol,
                            'setup_type' => $setupTypes[$alert->setup_type] ?? $alert->setup_type,
                            'setup_type_key' => $alert->setup_type,
                            'setup_score' => $alert->setup_score,
                            'raw_setup_score' => $alert->raw_setup_score ?? $alert->setup_score,
                            'heartbeat_score' => $alert->heartbeat_score,
                            'company' => $alert->company_name ?? '—',
                            'exchange' => $alert->exchange ?? '—',
                            'price' => $alert->price ? '$'.number_format((float) $alert->price, 2) : '—',
                            'market_cap' => $alert->market_cap ? '$'.number_format((float) $alert->market_cap) : '—',
                            'market_cap_category' => ucfirst((string) $alert->market_cap_category),
                            'shares_outstanding' => $alert->shares_outstanding ? number_format((int) $alert->shares_outstanding) : '—',
                            'float_shares' => $alert->float_shares ? number_format((int) $alert->float_shares) : '—',
                            'free_float' => $alert->free_float !== null ? number_format((float) $alert->free_float, 2).'%' : '—',
                            'avg_daily_volume' => $alert->avg_daily_volume ? number_format((int) $alert->avg_daily_volume) : '—',
                            'liquidity_turnover_pct' => $alert->liquidity_turnover_pct !== null ? number_format((float) $alert->liquidity_turnover_pct, 3).'%' : '—',
                            'liquidity_penalty_pct' => $alert->liquidity_penalty_pct ? number_format((float) $alert->liquidity_penalty_pct, 1).'%' : '0%',
                            'liquidity_penalty_points' => (int) ($alert->liquidity_penalty_points ?? 0),
                            'spike_date' => $alert->spike_date ? \Carbon\Carbon::parse($alert->spike_date)->toDateString() : '—',
                            'spike_volume' => number_format((int) $alert->spike_volume),
                            'prior_52w_max_volume' => number_format((int) $alert->prior_52w_max_volume),
                            'max_104w_volume' => number_format((int) $alert->max_104w_volume),
                            'is_52w_high_volume' => $alert->is_52w_high_volume ? 'Yes' : 'No',
                            'is_104w_high_volume' => $alert->is_104w_high_volume ? 'Yes' : 'No',
                            'days_since_previous_comparable_spike' => $alert->days_since_previous_comparable_spike ?? '—',
                            'spike_rarity_description' => $alert->spike_rarity_description ?? '—',
                            'base_start_date' => $alert->base_start_date ? \Carbon\Carbon::parse($alert->base_start_date)->toDateString() : '—',
                            'base_end_date' => $alert->base_end_date ? \Carbon\Carbon::parse($alert->base_end_date)->toDateString() : '—',
                            'base_duration_days' => $alert->base_duration_days ? $alert->base_duration_days.' days' : '—',
                            'base_high' => $alert->base_high ? '$'.number_format((float) $alert->base_high, 2) : '—',
                            'base_low' => $alert->base_low ? '$'.number_format((float) $alert->base_low, 2) : '—',
                            'range_compression_pct' => $alert->range_compression_pct !== null ? number_format((float) $alert->range_compression_pct, 2).'%' : '—',
                            'atr_contraction_ratio' => $alert->atr_contraction_ratio !== null ? number_format((float) $alert->atr_contraction_ratio, 2) : '—',
                            'volume_dry_up_score' => $alert->volume_dry_up_score !== null ? number_format(((float) $alert->volume_dry_up_score) * 100, 1).'%' : '—',
                            'slope' => $alert->slope !== null ? number_format((float) $alert->slope, 4) : '—',
                            'distance_to_breakout_pct' => $alert->distance_to_breakout_pct !== null ? number_format((float) $alert->distance_to_breakout_pct, 2).'%' : '—',
                            'ma_alignment' => $alert->ma_alignment ?? '—',
                            'relative_strength_score' => $alert->relative_strength_score !== null ? number_format((float) $alert->relative_strength_score, 1) : '—',
                            'quarterly_eps_growth_pct' => $alert->quarterly_eps_growth_pct !== null ? number_format((float) $alert->quarterly_eps_growth_pct, 1).'%' : '—',
                            'quarterly_revenue_growth_pct' => $alert->quarterly_revenue_growth_pct !== null ? number_format((float) $alert->quarterly_revenue_growth_pct, 1).'%' : '—',
                            'annual_eps_growth_pct' => $alert->annual_eps_growth_pct !== null ? number_format((float) $alert->annual_eps_growth_pct, 1).'%' : '—',
                            'roe_pct' => $alert->roe_pct !== null ? number_format((float) $alert->roe_pct, 1).'%' : '—',
                            'profit_margin_pct' => $alert->profit_margin_pct !== null ? number_format((float) $alert->profit_margin_pct, 1).'%' : '—',
                            'spike_relative_volume' => $alert->spike_relative_volume !== null ? number_format((float) $alert->spike_relative_volume, 1).'x' : '—',
                            'earnings_acceleration' => $alert->earnings_acceleration !== null ? number_format((float) $alert->earnings_acceleration, 1).' pts' : '—',
                            'sales_acceleration' => $alert->sales_acceleration !== null ? number_format((float) $alert->sales_acceleration, 1).' pts' : '—',
                            'eps_growth_sequence' => is_array($alert->eps_growth_sequence) ? implode(' → ', array_map(fn ($v) => $v.'%', $alert->eps_growth_sequence)) : '—',
                            'revenue_growth_sequence' => is_array($alert->revenue_growth_sequence) ? implode(' → ', array_map(fn ($v) => $v.'%', $alert->revenue_growth_sequence)) : '—',
                            'reason_summary' => $alert->reason_summary ?? '—',
                            'status' => ucfirst((string) $alert->status),
                            'detected_at' => $alert->detected_at ? \Carbon\Carbon::parse($alert->detected_at)->toDateTimeString() : '—',
                            'sent_at' => $alert->sent_at ? \Carbon\Carbon::parse($alert->sent_at)->toDateTimeString() : '—',
                            'score_breakdown' => $breakdownWithPct,
                        ];
                    @endphp
                    <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-900/50"
                        x-on:click="selected = @js($modalData); modalOpen = true">
                        <td class="font-semibold">{{ $alert->symbol }}</td>
                        <td class="whitespace-nowrap text-xs">{{ $setupTypes[$alert->setup_type] ?? $alert->setup_type }}</td>
                        <td class="text-right font-semibold text-sky-600 dark:text-sky-400">{{ $alert->setup_score }}</td>
                        <td>{{ $alert->company_name ?? '—' }}</td>
                        <td>{{ $alert->exchange ?? '—' }}</td>
                        <td class="text-right">{{ $alert->price ? '$'.number_format((float) $alert->price, 2) : '—' }}</td>
                        <td class="text-right">{{ $alert->market_cap ? '$'.number_format((float) $alert->market_cap) : '—' }}</td>
                        <td class="text-right whitespace-nowrap">{{ $alert->spike_date ? \Carbon\Carbon::parse($alert->spike_date)->toDateString() : '—' }}</td>
                        <td class="text-right">{{ number_format((int) $alert->spike_volume) }}</td>
                        <td class="text-right">{{ $alert->base_duration_days ?? '—' }}</td>
                        <td class="text-right">{{ $alert->range_compression_pct !== null ? number_format((float) $alert->range_compression_pct, 1).'%' : '—' }}</td>
                        <td class="text-right whitespace-nowrap text-xs text-zinc-500">
                            {{ $alert->detected_at ? \Carbon\Carbon::parse($alert->detected_at)->diffForHumans() : '—' }}
                        </td>
                        <td class="text-right whitespace-nowrap" x-on:click.stop>
                            @if ($isWatched)
                                <span class="text-xs text-zinc-500">{{ __('Already watched') }}</span>
                            @else
                                <button type="button"
                                        wire:click="addToWatchlist({{ $alert->id }})"
                                        class="da-btn-primary text-xs">
                                    {{ __('Add to Setup') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13" class="text-center text-sm text-zinc-500 py-6">
                            {{ __('No stock buy setup alerts yet.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-4">
            {{ $alerts->links() }}
        </div>
    </div>

    {{-- Detail Modal --}}
    <div x-cloak x-show="modalOpen" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="setup-detail-title" role="dialog" aria-modal="true">
        <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
            <div x-show="modalOpen" x-transition.opacity class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm" x-on:click="modalOpen = false"></div>

            <div x-show="modalOpen"
                 x-transition
                 class="relative w-full max-w-6xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-zinc-200 dark:bg-zinc-950 dark:ring-zinc-800">
                <div class="flex items-start justify-between gap-4 border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-800 dark:bg-zinc-900/80">
                    <div>
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 id="setup-detail-title" class="text-2xl font-semibold" x-text="selected?.symbol ?? ''"></h2>
                            <span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-medium text-sky-700 dark:bg-sky-900/60 dark:text-sky-200" x-text="selected?.setup_type ?? ''"></span>
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-200">
                                {{ __('Setup score') }}: <span x-text="selected?.setup_score ?? '—'"></span>
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400" x-text="selected?.company ?? '—'"></p>
                    </div>
                    <button type="button" class="rounded-full p-2 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-white" x-on:click="modalOpen = false">
                        <span class="sr-only">{{ __('Close') }}</span>
                        ✕
                    </button>
                </div>

                <div class="max-h-[78vh] overflow-y-auto px-6 py-5">
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                            <h3 class="mb-3 font-semibold">{{ __('Core setup') }}</h3>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <dt class="text-zinc-500">{{ __('Symbol') }}</dt><dd class="text-right font-medium" x-text="selected?.symbol"></dd>
                                <dt class="text-zinc-500">{{ __('Setup type') }}</dt><dd class="text-right font-medium" x-text="selected?.setup_type"></dd>
                                <dt class="text-zinc-500">{{ __('Setup score') }}</dt><dd class="text-right font-medium" x-text="selected?.setup_score"></dd>
                                <dt class="text-zinc-500">{{ __('Raw setup score') }}</dt><dd class="text-right font-medium" x-text="selected?.raw_setup_score"></dd>
                                <dt class="text-zinc-500">{{ __('Heartbeat') }}</dt><dd class="text-right font-medium" x-text="selected?.heartbeat_score"></dd>
                                <dt class="text-zinc-500">{{ __('Status') }}</dt><dd class="text-right font-medium" x-text="selected?.status"></dd>
                                <dt class="text-zinc-500">{{ __('Detected') }}</dt><dd class="text-right font-medium" x-text="selected?.detected_at"></dd>
                                <dt class="text-zinc-500">{{ __('Sent') }}</dt><dd class="text-right font-medium" x-text="selected?.sent_at"></dd>
                            </dl>
                        </div>

                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                            <h3 class="mb-3 font-semibold">{{ __('Market data') }}</h3>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <dt class="text-zinc-500">{{ __('Company') }}</dt><dd class="text-right font-medium" x-text="selected?.company"></dd>
                                <dt class="text-zinc-500">{{ __('Exchange') }}</dt><dd class="text-right font-medium" x-text="selected?.exchange"></dd>
                                <dt class="text-zinc-500">{{ __('Price') }}</dt><dd class="text-right font-medium" x-text="selected?.price"></dd>
                                <dt class="text-zinc-500">{{ __('Market cap') }}</dt><dd class="text-right font-medium" x-text="selected?.market_cap"></dd>
                                <dt class="text-zinc-500">{{ __('Mcap cat.') }}</dt><dd class="text-right font-medium" x-text="selected?.market_cap_category"></dd>
                                <dt class="text-zinc-500">{{ __('Shares out') }}</dt><dd class="text-right font-medium" x-text="selected?.shares_outstanding"></dd>
                                <dt class="text-zinc-500">{{ __('Float shares') }}</dt><dd class="text-right font-medium" x-text="selected?.float_shares"></dd>
                                <dt class="text-zinc-500">{{ __('Free float') }}</dt><dd class="text-right font-medium" x-text="selected?.free_float"></dd>
                                <dt class="text-zinc-500">{{ __('Avg daily volume') }}</dt><dd class="text-right font-medium" x-text="selected?.avg_daily_volume"></dd>
                                <dt class="text-zinc-500">{{ __('Liquidity turnover') }}</dt><dd class="text-right font-medium" x-text="selected?.liquidity_turnover_pct"></dd>
                                <dt class="text-zinc-500">{{ __('Liquidity penalty') }}</dt><dd class="text-right font-medium"><span x-text="selected?.liquidity_penalty_pct"></span> <span class="text-zinc-400">(<span x-text="selected?.liquidity_penalty_points"></span> pts)</span></dd>
                            </dl>
                        </div>

                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                            <h3 class="mb-3 font-semibold">{{ __('Reason') }}</h3>
                            <p class="whitespace-pre-line text-sm leading-6 text-zinc-700 dark:text-zinc-300" x-text="selected?.reason_summary ?? '—'"></p>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                            <h3 class="mb-3 font-semibold">{{ __('Technical details') }}</h3>
                            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <dt class="text-zinc-500">{{ __('Spike date') }}</dt><dd class="text-right font-medium" x-text="selected?.spike_date"></dd>
                                <dt class="text-zinc-500">{{ __('Spike volume') }}</dt><dd class="text-right font-medium" x-text="selected?.spike_volume"></dd>
                                <dt class="text-zinc-500">{{ __('Prior 52w max vol') }}</dt><dd class="text-right font-medium" x-text="selected?.prior_52w_max_volume"></dd>
                                <dt class="text-zinc-500">{{ __('Max 104w volume') }}</dt><dd class="text-right font-medium" x-text="selected?.max_104w_volume"></dd>
                                <dt class="text-zinc-500">{{ __('52w high-volume spike') }}</dt><dd class="text-right font-medium" x-text="selected?.is_52w_high_volume"></dd>
                                <dt class="text-zinc-500">{{ __('104w high-volume spike') }}</dt><dd class="text-right font-medium" x-text="selected?.is_104w_high_volume"></dd>
                                <dt class="text-zinc-500">{{ __('Days since comparable spike') }}</dt><dd class="text-right font-medium" x-text="selected?.days_since_previous_comparable_spike"></dd>
                                <dt class="text-zinc-500">{{ __('Base start') }}</dt><dd class="text-right font-medium" x-text="selected?.base_start_date"></dd>
                                <dt class="text-zinc-500">{{ __('Base end') }}</dt><dd class="text-right font-medium" x-text="selected?.base_end_date"></dd>
                                <dt class="text-zinc-500">{{ __('Base days') }}</dt><dd class="text-right font-medium" x-text="selected?.base_duration_days"></dd>
                                <dt class="text-zinc-500">{{ __('Base high') }}</dt><dd class="text-right font-medium" x-text="selected?.base_high"></dd>
                                <dt class="text-zinc-500">{{ __('Base low') }}</dt><dd class="text-right font-medium" x-text="selected?.base_low"></dd>
                                <dt class="text-zinc-500">{{ __('Range compression') }}</dt><dd class="text-right font-medium" x-text="selected?.range_compression_pct"></dd>
                                <dt class="text-zinc-500">{{ __('ATR ratio') }}</dt><dd class="text-right font-medium" x-text="selected?.atr_contraction_ratio"></dd>
                                <dt class="text-zinc-500">{{ __('Volume dry-up') }}</dt><dd class="text-right font-medium" x-text="selected?.volume_dry_up_score"></dd>
                                <dt class="text-zinc-500">{{ __('Slope') }}</dt><dd class="text-right font-medium" x-text="selected?.slope"></dd>
                                <dt class="text-zinc-500">{{ __('Distance to BO') }}</dt><dd class="text-right font-medium" x-text="selected?.distance_to_breakout_pct"></dd>
                                <dt class="text-zinc-500">{{ __('MA alignment') }}</dt><dd class="text-right font-medium" x-text="selected?.ma_alignment"></dd>
                                <dt class="text-zinc-500">{{ __('Relative strength') }}</dt><dd class="text-right font-medium" x-text="selected?.relative_strength_score"></dd>
                            </dl>
                        </div>

                        <div class="space-y-4">
                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                <h3 class="mb-3 font-semibold">{{ __('Score breakdown') }}</h3>
                                <template x-if="selected?.score_breakdown?.length">
                                    <div class="space-y-3">
                                        <template x-for="component in selected.score_breakdown" :key="component.label">
                                            <div>
                                                <div class="mb-1 flex justify-between gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                                                    <span x-text="component.label"></span>
                                                    <span><span x-text="component.points"></span>/<span x-text="component.max"></span></span>
                                                </div>
                                                <div class="h-2 rounded-full bg-zinc-200 dark:bg-zinc-800">
                                                    <div class="h-2 rounded-full bg-emerald-500" :style="`width: ${component.pct}%`"></div>
                                                </div>
                                                <div class="mt-1 text-[11px] text-zinc-400" x-show="component.value" x-text="component.value"></div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="! selected?.score_breakdown?.length">
                                    <p class="text-sm text-zinc-500">{{ __('No score breakdown available.') }}</p>
                                </template>
                            </div>

                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                <h3 class="mb-3 font-semibold">{{ __('Fundamentals') }}</h3>
                                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    <dt class="text-zinc-500">{{ __('EPS YoY') }}</dt><dd class="text-right font-medium" x-text="selected?.quarterly_eps_growth_pct"></dd>
                                    <dt class="text-zinc-500">{{ __('EPS acceleration') }}</dt><dd class="text-right font-medium" x-text="selected?.earnings_acceleration"></dd>
                                    <dt class="text-zinc-500">{{ __('EPS sequence') }}</dt><dd class="text-right font-medium" x-text="selected?.eps_growth_sequence"></dd>
                                    <dt class="text-zinc-500">{{ __('Sales YoY') }}</dt><dd class="text-right font-medium" x-text="selected?.quarterly_revenue_growth_pct"></dd>
                                    <dt class="text-zinc-500">{{ __('Sales acceleration') }}</dt><dd class="text-right font-medium" x-text="selected?.sales_acceleration"></dd>
                                    <dt class="text-zinc-500">{{ __('Sales sequence') }}</dt><dd class="text-right font-medium" x-text="selected?.revenue_growth_sequence"></dd>
                                    <dt class="text-zinc-500">{{ __('Annual EPS growth') }}</dt><dd class="text-right font-medium" x-text="selected?.annual_eps_growth_pct"></dd>
                                    <dt class="text-zinc-500">{{ __('ROE') }}</dt><dd class="text-right font-medium" x-text="selected?.roe_pct"></dd>
                                    <dt class="text-zinc-500">{{ __('Profit margin') }}</dt><dd class="text-right font-medium" x-text="selected?.profit_margin_pct"></dd>
                                    <dt class="text-zinc-500">{{ __('Spike relative volume') }}</dt><dd class="text-right font-medium" x-text="selected?.spike_relative_volume"></dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Configuration Modal Dialog --}}
    @if ($configModalOpen)
        <div x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="buy-setup-config-title" role="dialog" aria-modal="true">
            <div class="flex min-h-full items-center justify-center p-4 sm:p-6">
                <div class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm" wire:click="closeConfigModal"></div>

                <div class="relative w-full max-w-5xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-zinc-200 dark:bg-zinc-950 dark:ring-zinc-800">
                    {{-- Modal Header --}}
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-900/80">
                        <div>
                            <h2 id="buy-setup-config-title" class="text-xl font-semibold flex items-center gap-2">
                                <svg class="size-5 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.6 6.6 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                {{ __('Buy Setup Configuration') }}
                            </h2>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ __('Configure buy setup parameters, scanner settings, setup types, and score component weights.') }}
                            </p>
                        </div>
                        <button type="button" class="rounded-full p-2 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-white" wire:click="closeConfigModal">
                            <span class="sr-only">{{ __('Close') }}</span>
                            ✕
                        </button>
                    </div>

                    {{-- Navigation Tabs --}}
                    <div class="flex border-b border-zinc-200 px-6 bg-zinc-50/50 dark:border-zinc-800 dark:bg-zinc-900/40 text-sm">
                        <button type="button"
                                wire:click="$set('configTab', 'types')"
                                class="border-b-2 px-4 py-2.5 font-medium transition {{ $configTab === 'types' ? 'border-sky-500 text-sky-600 dark:text-sky-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}">
                            {{ __('Setup Types & Scoring') }}
                        </button>
                        <button type="button"
                                wire:click="$set('configTab', 'scanner')"
                                class="border-b-2 px-4 py-2.5 font-medium transition {{ $configTab === 'scanner' ? 'border-sky-500 text-sky-600 dark:text-sky-400' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' }}">
                            {{ __('Scanner & Global Filters') }}
                        </button>
                    </div>

                    {{-- Flash Alert inside Modal --}}
                    @if ($configFlash)
                        <div class="mx-6 mt-4 rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200">
                            {{ $configFlash }}
                        </div>
                    @endif

                    {{-- Modal Body --}}
                    <div class="max-h-[68vh] overflow-y-auto px-6 py-5 space-y-6">
                        @if ($configTab === 'types')
                            {{-- Setup Types Selection and Management --}}
                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/30 space-y-4">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div class="flex-1">
                                        <label class="da-label font-semibold">{{ __('Selected Setup Type') }}</label>
                                        <div class="flex items-center gap-2">
                                            <select wire:model.live="selectedConfigSetupType" class="da-input font-medium">
                                                @foreach ($configState['setup_types'] ?? [] as $tKey => $tVal)
                                                    <option value="{{ $tKey }}">
                                                        {{ $tVal['label'] ?? $tKey }} {{ empty($tVal['enabled']) ? ' (Disabled)' : '' }}
                                                    </option>
                                                @endforeach
                                            </select>

                                            @if ($selectedConfigSetupType !== 'heartbeat_consolidation_spike')
                                                <button type="button"
                                                        wire:click="removeSetupType('{{ $selectedConfigSetupType }}')"
                                                        wire:confirm="Are you sure you want to delete this setup type?"
                                                        class="da-btn-danger text-xs whitespace-nowrap">
                                                    {{ __('Delete Type') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-3 pt-4 sm:pt-0">
                                        <label class="inline-flex items-center gap-2 text-sm font-medium">
                                            <input type="checkbox"
                                                   wire:key="type-enabled-{{ $selectedConfigSetupType }}"
                                                   wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.enabled"
                                                   class="rounded border-zinc-300 text-sky-600 focus:ring-sky-500 size-4">
                                            {{ __('Setup Type Enabled') }}
                                        </label>
                                    </div>
                                </div>

                                <div>
                                    <label class="da-label">{{ __('Display Label') }}</label>
                                    <input type="text"
                                           wire:key="type-label-{{ $selectedConfigSetupType }}"
                                           wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.label"
                                           class="da-input"
                                           placeholder="Setup Type Name">
                                </div>

                                {{-- Add New Setup Type Section --}}
                                <div class="border-t border-zinc-200 dark:border-zinc-800 pt-3">
                                    <label class="da-label text-xs font-semibold text-zinc-500 uppercase tracking-wider">{{ __('Add New Setup Type') }}</label>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-1">
                                        <input type="text"
                                               wire:model="newSetupTypeKey"
                                               class="da-input text-xs"
                                               placeholder="Key (e.g. range_breakout)">
                                        <input type="text"
                                               wire:model="newSetupTypeLabel"
                                               class="da-input text-xs"
                                               placeholder="Label (e.g. Range Breakout)">
                                        <button type="button"
                                                wire:click="addSetupType"
                                                class="da-btn-secondary text-xs whitespace-nowrap">
                                            + {{ __('Add Setup Type') }}
                                        </button>
                                    </div>
                                    @error('newSetupTypeKey') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                                    @error('newSetupTypeLabel') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div wire:key="setup-type-config-panel-{{ $selectedConfigSetupType }}" class="space-y-6">
                                {{-- Technical Parameters for Selected Setup Type --}}
                                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                    <h3 class="mb-3 font-semibold text-sm uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                                        {{ __('Technical Thresholds') }} ({{ $configState['setup_types'][$selectedConfigSetupType]['label'] ?? $selectedConfigSetupType }})
                                    </h3>
                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                        <div>
                                            <label class="da-label">{{ __('Recent Spike Window (Days)') }}</label>
                                            <input type="number" step="1" wire:key="type-spike-window-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.recent_spike_window_days" class="da-input">
                                        </div>
                                        <div>
                                            <label class="da-label">{{ __('Max Spike Age (Days)') }}</label>
                                            <input type="number" step="1" wire:key="type-spike-age-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.max_spike_age_days" class="da-input">
                                        </div>
                                        <div>
                                            <label class="da-label">{{ __('Min Base Days') }}</label>
                                            <input type="number" step="1" wire:key="type-min-base-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.min_base_days" class="da-input">
                                        </div>
                                        <div>
                                            <label class="da-label">{{ __('Max Base Days') }}</label>
                                            <input type="number" step="1" wire:key="type-max-base-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.max_base_days" class="da-input">
                                        </div>
                                        <div>
                                            <label class="da-label">{{ __('Max Range Compression (%)') }}</label>
                                            <input type="number" step="0.1" wire:key="type-max-range-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.max_range_compression_pct" class="da-input">
                                        </div>
                                        <div>
                                            <label class="da-label">{{ __('Max ATR Ratio') }}</label>
                                            <input type="number" step="0.01" wire:key="type-max-atr-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.max_atr_ratio" class="da-input">
                                        </div>
                                    </div>
                                </div>

                                {{-- Sleepy Volume Penalties --}}
                                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                    <h3 class="mb-3 font-semibold text-sm uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                                        {{ __('Sleepy-Volume Liquidity Penalties (%)') }}
                                    </h3>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                                        {{ __('Max percentage score deduction applied based on turnover (average daily volume / float shares).') }}
                                    </p>
                                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                                        <div>
                                            <label class="da-label">{{ __('Large Cap Penalty %') }}</label>
                                            <input type="number" step="1" wire:key="type-sleepy-large-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.sleepy_volume_large_cap_penalty_pct" class="da-input">
                                        </div>
                                        <div>
                                            <label class="da-label">{{ __('Medium Cap Penalty %') }}</label>
                                            <input type="number" step="1" wire:key="type-sleepy-med-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.sleepy_volume_medium_cap_penalty_pct" class="da-input">
                                        </div>
                                        <div>
                                            <label class="da-label">{{ __('Small Cap Penalty %') }}</label>
                                            <input type="number" step="1" wire:key="type-sleepy-small-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.sleepy_volume_small_cap_penalty_pct" class="da-input">
                                        </div>
                                        <div>
                                            <label class="da-label">{{ __('Micro Cap Penalty %') }}</label>
                                            <input type="number" step="1" wire:key="type-sleepy-micro-{{ $selectedConfigSetupType }}" wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.sleepy_volume_micro_cap_penalty_pct" class="da-input">
                                        </div>
                                    </div>
                                </div>

                                {{-- Weighted Score Components --}}
                                @php
                                    $currentTypeWeights = $configState['setup_types'][$selectedConfigSetupType]['score_weights'] ?? [];
                                    $totalWeightSum = collect($currentTypeWeights)
                                        ->filter(fn ($w) => is_array($w) ? (bool) ($w['enabled'] ?? true) : true)
                                        ->sum(fn ($w) => is_array($w) ? (int) ($w['weight'] ?? 0) : (int) $w);
                                @endphp
                                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                    <div class="flex items-center justify-between mb-3">
                                        <div>
                                            <h3 class="font-semibold text-sm uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                                                {{ __('Weighted Score Components') }}
                                            </h3>
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ __('Enable or disable individual components and adjust their point weights. Total score normalizes to 0–100.') }}
                                            </p>
                                        </div>
                                        <span class="text-xs font-semibold px-2.5 py-1 rounded-full {{ $totalWeightSum === 100 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200' }}">
                                            {{ __('Active Weight Sum') }}: {{ $totalWeightSum }} pts
                                        </span>
                                    </div>

                                    <div class="overflow-x-auto">
                                        <table class="da-table text-sm">
                                            <thead>
                                                <tr>
                                                    <th class="w-16 text-center">{{ __('Active') }}</th>
                                                    <th>{{ __('Score Component') }}</th>
                                                    <th class="w-40 text-right">{{ __('Weight (Points)') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @php
                                                    $components = [
                                                        'spike_rarity' => 'Spike rarity',
                                                        'base_duration' => 'Base duration',
                                                        'range_compression' => 'Range compression',
                                                        'atr_contraction' => 'ATR contraction',
                                                        'volume_dry_up' => 'Volume dry-up',
                                                        'breakout_distance' => 'Breakout distance',
                                                        'ma_alignment' => 'MA alignment',
                                                        'relative_strength' => 'Relative strength',
                                                        'earnings_acceleration' => 'Earnings acceleration',
                                                        'sales_acceleration' => 'Sales acceleration',
                                                    ];
                                                @endphp

                                                @foreach ($components as $cKey => $cLabel)
                                                    <tr wire:key="type-weight-row-{{ $selectedConfigSetupType }}-{{ $cKey }}">
                                                        <td class="text-center">
                                                            <input type="checkbox"
                                                                   wire:key="type-weight-enabled-{{ $selectedConfigSetupType }}-{{ $cKey }}"
                                                                   wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.score_weights.{{ $cKey }}.enabled"
                                                                   class="rounded border-zinc-300 text-sky-600 focus:ring-sky-500 size-4">
                                                        </td>
                                                        <td class="font-medium">
                                                            {{ __($cLabel) }}
                                                        </td>
                                                        <td class="text-right">
                                                            <input type="number"
                                                                   step="1"
                                                                   min="0"
                                                                   max="100"
                                                                   wire:key="type-weight-val-{{ $selectedConfigSetupType }}-{{ $cKey }}"
                                                                   wire:model="configState.setup_types.{{ $selectedConfigSetupType }}.score_weights.{{ $cKey }}.weight"
                                                                   class="da-input text-right py-1 w-28 inline-block">
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @else
                            {{-- Scanner & Global Filters --}}
                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800 space-y-4">
                                <h3 class="font-semibold text-sm uppercase tracking-wider text-zinc-600 dark:text-zinc-300">
                                    {{ __('Scanner Controls & UI Defaults') }}
                                </h3>

                                <div class="flex items-center gap-3">
                                    <label class="inline-flex items-center gap-2 text-sm font-medium">
                                        <input type="checkbox"
                                               wire:model="configState.scanner_enabled"
                                               class="rounded border-zinc-300 text-sky-600 focus:ring-sky-500 size-4">
                                        {{ __('Scanner Enabled (BUY_SETUP_SCANNER_ENABLED)') }}
                                    </label>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div>
                                        <label class="da-label">{{ __('Min Setup Score (UI Default Filter)') }}</label>
                                        <input type="number" step="1" wire:model="configState.min_setup_score" class="da-input">
                                    </div>
                                    <div>
                                        <label class="da-label">{{ __('Notify Min Setup Score') }}</label>
                                        <input type="number" step="1" wire:model="configState.notify_min_setup_score" class="da-input">
                                    </div>
                                    <div>
                                        <label class="da-label">{{ __('Min Heartbeat Score') }}</label>
                                        <input type="number" step="1" wire:model="configState.min_heartbeat_score" class="da-input">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div>
                                        <label class="da-label">{{ __('Min Market Cap ($)') }}</label>
                                        <input type="number" step="1" wire:model="configState.min_market_cap" class="da-input">
                                    </div>
                                    <div>
                                        <label class="da-label">{{ __('Max Symbols Per Run') }}</label>
                                        <input type="number" step="1" wire:model="configState.max_symbols" class="da-input">
                                    </div>
                                    <div>
                                        <label class="da-label">{{ __('History Lookback (Days)') }}</label>
                                        <input type="number" step="1" wire:model="configState.history_lookback_days" class="da-input">
                                    </div>
                                </div>

                                <div>
                                    <label class="da-label">{{ __('Exchanges (Comma-separated)') }}</label>
                                    <input type="text" wire:model="configState.exchanges_text" class="da-input" placeholder="NYSE, NASDAQ, TSX, TSXV, AMEX, OTC">
                                </div>

                                <div>
                                    <label class="da-label">{{ __('Benchmark Symbols (Comma-separated)') }}</label>
                                    <input type="text" wire:model="configState.benchmark_symbols_text" class="da-input" placeholder="SPY, IWM">
                                </div>

                                <div>
                                    <label class="da-label">{{ __('Notification Email') }}</label>
                                    <input type="email" wire:model="configState.notification_email" class="da-input" placeholder="j@7pro.ca">
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Modal Footer Actions --}}
                    <div class="flex items-center justify-between border-t border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-800 dark:bg-zinc-900/80">
                        <button type="button"
                                wire:click="resetConfigToDefaults"
                                wire:confirm="Are you sure you want to reset all buy setup configurations to default values?"
                                class="da-btn-secondary text-xs text-rose-600 dark:text-rose-400">
                            {{ __('Reset to Defaults') }}
                        </button>

                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="closeConfigModal" class="da-btn-secondary">
                                {{ __('Cancel') }}
                            </button>
                            <button type="button" wire:click="saveConfig" class="da-btn-primary">
                                {{ __('Save Configuration') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
