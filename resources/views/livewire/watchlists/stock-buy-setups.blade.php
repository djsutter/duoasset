<div class="space-y-6" wire:poll.30s>
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Stock Buy Setups') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('High-volume spikes following tight consolidation bases, scored 0–100.') }}
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
        <div class="grid grid-cols-1 gap-3 md:grid-cols-6">
            <div>
                <label class="da-label">{{ __('Min heartbeat score') }}</label>
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
        <div class="mt-3">
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="unwatchedOnly"> {{ __('Hide already-watched') }}
            </label>
        </div>
    </div>

    <div class="da-card overflow-x-auto">
        <table class="da-table">
            <thead>
                <tr>
                    <th>{{ __('Detected') }}</th>
                    <th>{{ __('Symbol') }}</th>
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
                        <td colspan="15" class="text-center text-sm text-zinc-500 py-6">
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
