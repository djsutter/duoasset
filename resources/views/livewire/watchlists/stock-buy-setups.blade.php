<div class="space-y-6" wire:poll.30s
     x-data="{ modalOpen: false, selected: null }"
     x-on:keydown.escape.window="modalOpen = false">
    <style>[x-cloak] { display: none !important; }</style>

    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Stock Buy Setups') }}</h1>
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
        <div class="grid grid-cols-1 gap-3 md:grid-cols-8">
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
                    <th>{{ __('Symbol') }}</th>
                    <th>{{ __('Setup type') }}</th>
                    <th class="text-right">
                        <button type="button" wire:click="sortByColumn('setup_score')" class="inline-flex items-center gap-1 font-semibold hover:text-sky-600 dark:hover:text-sky-400">
                            {{ __('Setup score') }}
                            @if ($sortBy === 'setup_score')
                                <span class="text-xs">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                            @endif
                        </button>
                    </th>
                    <th>{{ __('Company') }}</th>
                    <th>{{ __('Mcap cat.') }}</th>
                    <th>{{ __('Spike date') }}</th>
                    <th class="text-right">{{ __('Spike vol') }}</th>
                    <th class="text-right">{{ __('Base days') }}</th>
                    <th class="text-right">{{ __('Range %') }}</th>
                    <th class="text-right">{{ __('ATR ratio') }}</th>
                    <th class="text-right">{{ __('RS') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($alerts as $alert)
                    @php
                        $breakdown = $scoreBreakdowns[$alert->id] ?? [];
                        $formattedBreakdown = collect($breakdown)->map(fn ($component) => [
                            'label' => __($component['label']),
                            'points' => $component['points'],
                            'max' => $component['max'],
                            'value' => $component['value'] ?? null,
                            'pct' => ($component['max'] ?? 0) > 0 ? min(100, round(($component['points'] / $component['max']) * 100)) : 0,
                        ])->values()->all();
                        $modalData = [
                            'symbol' => $alert->symbol,
                            'setup_type' => $setupTypes[$alert->setup_type] ?? $alert->setup_type,
                            'setup_type_key' => $alert->setup_type,
                            'setup_score' => $alert->setup_score,
                            'company' => $alert->company_name ?? '—',
                            'exchange' => $alert->exchange ?? '—',
                            'market_cap' => $alert->market_cap ? number_format((int) $alert->market_cap) : '—',
                            'market_cap_category' => $alert->market_cap_category ?? '—',
                            'price' => $alert->price !== null ? number_format((float) $alert->price, 2) : '—',
                            'shares_outstanding' => $alert->shares_outstanding ? number_format((int) $alert->shares_outstanding) : '—',
                            'float_shares' => $alert->float_shares ? number_format((int) $alert->float_shares) : '—',
                            'free_float' => $alert->free_float !== null ? number_format((float) $alert->free_float, 1).'%' : '—',
                            'detected_at' => optional($alert->detected_at)->format('Y-m-d H:i') ?? '—',
                            'sent_at' => optional($alert->sent_at)->format('Y-m-d H:i') ?? '—',
                            'status' => $alert->status,
                            'spike_date' => optional($alert->spike_date)->toDateString() ?? '—',
                            'spike_volume' => $alert->spike_volume ? number_format((int) $alert->spike_volume) : '—',
                            'prior_52w_max_volume' => $alert->prior_52w_max_volume ? number_format((int) $alert->prior_52w_max_volume) : '—',
                            'max_104w_volume' => $alert->max_104w_volume ? number_format((int) $alert->max_104w_volume) : '—',
                            'is_52w_high_volume' => $alert->is_52w_high_volume ? 'Yes' : 'No',
                            'is_104w_high_volume' => $alert->is_104w_high_volume ? 'Yes' : 'No',
                            'days_since_previous_comparable_spike' => $alert->days_since_previous_comparable_spike ?? '—',
                            'base_start_date' => optional($alert->base_start_date)->toDateString() ?? '—',
                            'base_end_date' => optional($alert->base_end_date)->toDateString() ?? '—',
                            'base_duration_days' => $alert->base_duration_days ?? '—',
                            'base_high' => $alert->base_high !== null ? number_format((float) $alert->base_high, 2) : '—',
                            'base_low' => $alert->base_low !== null ? number_format((float) $alert->base_low, 2) : '—',
                            'range_compression_pct' => $alert->range_compression_pct !== null ? number_format((float) $alert->range_compression_pct, 1).'%' : '—',
                            'atr_contraction_ratio' => $alert->atr_contraction_ratio !== null ? number_format((float) $alert->atr_contraction_ratio, 2) : '—',
                            'volume_dry_up_score' => $alert->volume_dry_up_score !== null ? number_format((float) $alert->volume_dry_up_score, 2) : '—',
                            'slope' => $alert->slope !== null ? number_format((float) $alert->slope, 4) : '—',
                            'distance_to_breakout_pct' => $alert->distance_to_breakout_pct !== null ? number_format((float) $alert->distance_to_breakout_pct, 1).'%' : '—',
                            'ma_alignment' => $alert->ma_alignment ?? '—',
                            'relative_strength_score' => $alert->relative_strength_score !== null ? number_format((float) $alert->relative_strength_score, 1) : '—',
                            'heartbeat_score' => $alert->heartbeat_score ?? '—',
                            'earnings_acceleration' => $alert->earnings_acceleration !== null ? number_format((float) $alert->earnings_acceleration, 1).' pts' : '—',
                            'sales_acceleration' => $alert->sales_acceleration !== null ? number_format((float) $alert->sales_acceleration, 1).' pts' : '—',
                            'quarterly_eps_growth_pct' => $alert->quarterly_eps_growth_pct !== null ? number_format((float) $alert->quarterly_eps_growth_pct, 1).'%' : '—',
                            'quarterly_revenue_growth_pct' => $alert->quarterly_revenue_growth_pct !== null ? number_format((float) $alert->quarterly_revenue_growth_pct, 1).'%' : '—',
                            'annual_eps_growth_pct' => $alert->annual_eps_growth_pct !== null ? number_format((float) $alert->annual_eps_growth_pct, 1).'%' : '—',
                            'roe_pct' => $alert->roe_pct !== null ? number_format((float) $alert->roe_pct, 1).'%' : '—',
                            'profit_margin_pct' => $alert->profit_margin_pct !== null ? number_format((float) $alert->profit_margin_pct, 1).'%' : '—',
                            'spike_relative_volume' => $alert->spike_relative_volume !== null ? number_format((float) $alert->spike_relative_volume, 1).'x' : '—',
                            'eps_growth_sequence' => ! empty($alert->eps_growth_sequence) ? collect($alert->eps_growth_sequence)->map(fn ($v) => number_format((float) $v, 1).'%')->join(', ') : '—',
                            'revenue_growth_sequence' => ! empty($alert->revenue_growth_sequence) ? collect($alert->revenue_growth_sequence)->map(fn ($v) => number_format((float) $v, 1).'%')->join(', ') : '—',
                            'reason_summary' => $alert->reason_summary ?? '—',
                            'score_breakdown' => $formattedBreakdown,
                        ];
                    @endphp
                    <tr class="cursor-pointer transition hover:bg-sky-50/70 dark:hover:bg-sky-950/30"
                        x-on:click="selected = @js($modalData); modalOpen = true">
                        <td class="font-semibold">{{ $alert->symbol }}</td>
                        <td class="whitespace-nowrap text-xs">{{ $setupTypes[$alert->setup_type] ?? $alert->setup_type }}</td>
                        <td class="text-right font-semibold text-sky-600 dark:text-sky-400">{{ $alert->setup_score }}</td>
                        <td>{{ $alert->company_name ?? '—' }}</td>
                        <td>{{ $alert->market_cap_category ?? '—' }}</td>
                        <td>{{ optional($alert->spike_date)->toDateString() ?? '—' }}</td>
                        <td class="text-right">
                            {{ $alert->spike_volume ? number_format((int) $alert->spike_volume) : '—' }}
                        </td>
                        <td class="text-right">{{ $alert->base_duration_days ?? '—' }}</td>
                        <td class="text-right">{{ $alert->range_compression_pct !== null ? number_format((float) $alert->range_compression_pct, 1) : '—' }}</td>
                        <td class="text-right">{{ $alert->atr_contraction_ratio !== null ? number_format((float) $alert->atr_contraction_ratio, 2) : '—' }}</td>
                        <td class="text-right">
                            {{ $alert->relative_strength_score !== null ? number_format((float) $alert->relative_strength_score, 1) : '—' }}
                        </td>
                        <td class="text-xs">{{ $alert->status }}</td>
                        <td x-on:click.stop>
                            @if ($watched->contains(strtoupper($alert->symbol)))
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
</div>
