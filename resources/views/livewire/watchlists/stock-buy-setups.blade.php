<div class="space-y-6" wire:poll.30s>
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Stock Buy Setups') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Filterable buy setup detections. Setup score ranks candidates within their setup type; heartbeat remains the consolidation/plateau quality component.') }}
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
                    <th>{{ __('Detected') }}</th>
                    <th>{{ __('Symbol') }}</th>
                    <th>{{ __('Setup type') }}</th>
                    <th class="text-right">{{ __('Setup score') }}</th>
                    <th>{{ __('Company') }}</th>
                    <th>{{ __('Mcap cat.') }}</th>
                    <th>{{ __('Spike date') }}</th>
                    <th class="text-right">{{ __('Spike vol') }}</th>
                    <th class="text-right">{{ __('Base days') }}</th>
                    <th class="text-right">{{ __('Range %') }}</th>
                    <th class="text-right">{{ __('ATR ratio') }}</th>
                    <th class="text-right">{{ __('Dist to BO %') }}</th>
                    <th class="text-right">{{ __('RS') }}</th>
                    <th class="text-right">{{ __('Heartbeat') }}</th>
                    <th>{{ __('Score breakdown') }}</th>
                    <th>{{ __('Fundamentals') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Reason') }}</th>
                    <th>{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($alerts as $alert)
                    <tr>
                        <td class="whitespace-nowrap text-xs">
                            {{ optional($alert->detected_at)->format('Y-m-d H:i') ?? '—' }}
                        </td>
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
                        <td class="text-right">{{ $alert->range_compression_pct ?? '—' }}</td>
                        <td class="text-right">{{ $alert->atr_contraction_ratio ?? '—' }}</td>
                        <td class="text-right">{{ $alert->distance_to_breakout_pct ?? '—' }}</td>
                        <td class="text-right">
                            {{ $alert->relative_strength_score !== null ? $alert->relative_strength_score : '—' }}
                        </td>
                        <td class="text-right font-semibold text-emerald-600 dark:text-emerald-400">
                            {{ $alert->heartbeat_score }}
                        </td>
                        <td class="min-w-64 text-xs">
                            @php($breakdown = $scoreBreakdowns[$alert->id] ?? [])
                            <div class="space-y-1.5">
                                @foreach ($breakdown as $component)
                                    @php($pct = $component['max'] > 0 ? min(100, round(($component['points'] / $component['max']) * 100)) : 0)
                                    <div title="{{ $component['value'] ?? '' }}">
                                        <div class="flex justify-between gap-2 text-[11px] text-zinc-500 dark:text-zinc-400">
                                            <span>{{ __($component['label']) }}</span>
                                            <span>{{ $component['points'] }}/{{ $component['max'] }}</span>
                                        </div>
                                        <div class="h-1.5 rounded bg-zinc-200 dark:bg-zinc-700">
                                            <div class="h-1.5 rounded bg-emerald-500" style="width: {{ $pct }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </td>
                        <td class="min-w-64 text-xs">
                            <div class="grid grid-cols-2 gap-x-3 gap-y-1">
                                <span class="text-zinc-500">{{ __('EPS YoY') }}</span>
                                <span class="text-right">{{ $alert->quarterly_eps_growth_pct !== null ? number_format((float) $alert->quarterly_eps_growth_pct, 1).'%' : '—' }}</span>
                                <span class="text-zinc-500">{{ __('EPS accel.') }}</span>
                                <span class="text-right">{{ $alert->earnings_acceleration !== null ? number_format((float) $alert->earnings_acceleration, 1).' pts' : '—' }}</span>
                                <span class="text-zinc-500">{{ __('Sales YoY') }}</span>
                                <span class="text-right">{{ $alert->quarterly_revenue_growth_pct !== null ? number_format((float) $alert->quarterly_revenue_growth_pct, 1).'%' : '—' }}</span>
                                <span class="text-zinc-500">{{ __('Sales accel.') }}</span>
                                <span class="text-right">{{ $alert->sales_acceleration !== null ? number_format((float) $alert->sales_acceleration, 1).' pts' : '—' }}</span>
                                <span class="text-zinc-500">{{ __('Annual EPS') }}</span>
                                <span class="text-right">{{ $alert->annual_eps_growth_pct !== null ? number_format((float) $alert->annual_eps_growth_pct, 1).'%' : '—' }}</span>
                                <span class="text-zinc-500">{{ __('ROE') }}</span>
                                <span class="text-right">{{ $alert->roe_pct !== null ? number_format((float) $alert->roe_pct, 1).'%' : '—' }}</span>
                                <span class="text-zinc-500">{{ __('Margin') }}</span>
                                <span class="text-right">{{ $alert->profit_margin_pct !== null ? number_format((float) $alert->profit_margin_pct, 1).'%' : '—' }}</span>
                                <span class="text-zinc-500">{{ __('Spike rel vol') }}</span>
                                <span class="text-right">{{ $alert->spike_relative_volume !== null ? number_format((float) $alert->spike_relative_volume, 1).'x' : '—' }}</span>
                            </div>
                            @if (! empty($alert->eps_growth_sequence) || ! empty($alert->revenue_growth_sequence))
                                <div class="mt-2 text-[11px] text-zinc-500 dark:text-zinc-400">
                                    @if (! empty($alert->eps_growth_sequence))
                                        <div>{{ __('EPS seq') }}: {{ collect($alert->eps_growth_sequence)->map(fn ($v) => number_format((float) $v, 1).'%')->join(', ') }}</div>
                                    @endif
                                    @if (! empty($alert->revenue_growth_sequence))
                                        <div>{{ __('Sales seq') }}: {{ collect($alert->revenue_growth_sequence)->map(fn ($v) => number_format((float) $v, 1).'%')->join(', ') }}</div>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="text-xs">{{ $alert->status }}</td>
                        <td class="text-xs text-zinc-500 max-w-xs">{{ $alert->reason_summary }}</td>
                        <td>
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
                        <td colspan="19" class="text-center text-sm text-zinc-500 py-6">
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
</div>
