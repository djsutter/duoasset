<div class="space-y-6" wire:poll.30s>
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('EPS Revision Alerts') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Analyst consensus EPS estimate raises and cuts for the next quarter.') }}
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
                <label class="da-label">{{ __('Min |revision| %') }}</label>
                <input type="number" step="0.01" wire:model.live.debounce.400ms="minRevisionPercent" class="da-input">
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
                <label class="da-label">{{ __('Detected from') }}</label>
                <input type="date" wire:model.live="dateFrom" class="da-input">
            </div>
            <div>
                <label class="da-label">{{ __('Detected to') }}</label>
                <input type="date" wire:model.live="dateTo" class="da-input">
            </div>
            <div>
                <label class="da-label">{{ __('Direction') }}</label>
                <select wire:model.live="direction" class="da-input">
                    <option value="both">{{ __('Both') }}</option>
                    <option value="positive">{{ __('Positive (Raised)') }}</option>
                    <option value="negative">{{ __('Negative (Cut)') }}</option>
                </select>
            </div>
        </div>
    </div>

    <div class="da-card overflow-x-auto">
        <table class="da-table">
            <thead>
                <tr>
                    <th>{{ __('Detected') }}</th>
                    <th>{{ __('Symbol') }}</th>
                    <th>{{ __('Company') }}</th>
                    <th>{{ __('Exchange') }}</th>
                    <th>{{ __('Period') }}</th>
                    <th class="text-right">{{ __('Previous Est.') }}</th>
                    <th class="text-right">{{ __('Latest Est.') }}</th>
                    <th>{{ __('Label') }}</th>
                    <th class="text-right">{{ __('Revision %') }}</th>
                    <th class="text-right">{{ __('Market Cap') }}</th>
                    <th>{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($alerts as $alert)
                    @php
                        $pct = (float) $alert->revision_percent;
                        $isNeg = $pct < 0;
                        $label = $isNeg ? '📉 EPS Target Cut' : '📈 EPS Target Raised';
                        $pctClass = $isNeg ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400';
                    @endphp
                    <tr>
                        <td class="whitespace-nowrap text-xs">
                            {{ optional($alert->detected_at)->format('Y-m-d H:i') ?? '—' }}
                        </td>
                        <td class="font-semibold">{{ $alert->symbol }}</td>
                        <td>{{ $alert->company_name ?? '—' }}</td>
                        <td>{{ $alert->exchange ?? '—' }}</td>
                        <td>{{ optional($alert->next_quarter_end_date)->toDateString() ?? '—' }}</td>
                        <td class="text-right">{{ $alert->previous_estimate }}</td>
                        <td class="text-right">{{ $alert->latest_estimate }}</td>
                        <td class="whitespace-nowrap text-xs {{ $pctClass }}">{{ $label }}</td>
                        <td class="text-right font-semibold {{ $pctClass }}">
                            {{ ($pct >= 0 ? '+' : '') . number_format($pct, 2) }}%
                        </td>
                        <td class="text-right">
                            {{ $alert->market_cap ? number_format((int) $alert->market_cap) : '—' }}
                        </td>
                        <td>
                            @if ($watched->contains(strtoupper($alert->symbol)))
                                <span class="text-xs text-zinc-500">{{ __('Already watched') }}</span>
                            @else
                                <button type="button"
                                        wire:click="addToWatchlist({{ $alert->id }})"
                                        class="da-btn-primary text-xs">
                                    {{ __('Add to Watchlist') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center text-sm text-zinc-500 py-6">
                            {{ __('No EPS revision alerts yet.') }}
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
