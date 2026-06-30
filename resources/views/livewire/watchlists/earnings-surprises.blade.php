<div class="space-y-6" wire:poll.30s>
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('EPS Surprise Alerts') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Auto-detected earnings beats from the FMP scanner.') }}
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
                <label class="da-label">{{ __('Symbol') }}</label>
                <input type="text" wire:model.live.debounce.400ms="symbol" class="da-input uppercase" placeholder="AAPL">
            </div>
            <div>
                <label class="da-label">{{ __('Min |EPS surprise| %') }}</label>
                <input type="number" step="0.01" wire:model.live.debounce.400ms="minSurprisePercent" class="da-input">
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
                <label class="da-label">{{ __('Report from') }}</label>
                <input type="date" wire:model.live="dateFrom" class="da-input">
            </div>
            <div>
                <label class="da-label">{{ __('Report to') }}</label>
                <input type="date" wire:model.live="dateTo" class="da-input">
            </div>
            <div>
                <label class="da-label">{{ __('Direction') }}</label>
                <select wire:model.live="direction" class="da-input">
                    <option value="both">{{ __('Both') }}</option>
                    <option value="positive">{{ __('Positive (Beat)') }}</option>
                    <option value="negative">{{ __('Negative (Miss)') }}</option>
                </select>
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model.live="alertedOnly">
                    {{ __('Alerted only') }}
                </label>
            </div>
        </div>
    </div>

    <div class="da-card overflow-x-auto">
        <table class="da-table">
            <thead>
                <tr>
                    <th>{{ __('Detected') }}</th>
                    <th>
                        <button type="button" wire:click="sortBy('symbol')" class="da-sort">
                            {{ __('Symbol') }}
                            @if ($sortField === 'symbol')
                                @if ($sortDirection === 'asc')
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                @else
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                @endif
                            @endif
                        </button>
                    </th>
                    <th>{{ __('Company') }}</th>
                    <th>{{ __('Exchange') }}</th>
                    <th class="text-right">{{ __('Market Cap') }}</th>
                    <th class="text-right">{{ __('EPS Est.') }}</th>
                    <th class="text-right">{{ __('EPS Actual') }}</th>
                    <th>{{ __('Label') }}</th>
                    <th class="text-right">
                        <button type="button" wire:click="sortBy('eps_surprise_percent')" class="da-sort">
                            {{ __('EPS Surprise %') }}
                            @if ($sortField === 'eps_surprise_percent')
                                @if ($sortDirection === 'asc')
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                @else
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                @endif
                            @endif
                        </button>
                    </th>
                    <th class="text-right">
                        <button type="button" wire:click="sortBy('revenue_surprise_percent')" class="da-sort">
                            {{ __('Rev. Surprise %') }}
                            @if ($sortField === 'revenue_surprise_percent')
                                @if ($sortDirection === 'asc')
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
                                @else
                                    <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                                @endif
                            @endif
                        </button>
                    </th>
                    <th class="text-right">{{ __('Rel. Vol.') }}</th>
                    <th class="text-right">{{ __('Score') }}</th>
                    <th>{{ __('Action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td class="whitespace-nowrap text-xs">
                            {{ optional($event->detected_at)->format('Y-m-d H:i') ?? '—' }}
                        </td>
                        <td class="font-semibold">{{ $event->symbol }}</td>
                        <td>{{ $event->company_name ?? '—' }}</td>
                        <td>{{ $event->exchange ?? '—' }}</td>
                        <td class="text-right">
                            {{ $event->market_cap ? number_format((int) $event->market_cap) : '—' }}
                        </td>
                        <td class="text-right">{{ $event->eps_estimated ?? '—' }}</td>
                        <td class="text-right">{{ $event->eps_actual ?? '—' }}</td>
                        @php
                            $pct = $event->eps_surprise_percent !== null ? (float) $event->eps_surprise_percent : null;
                            $isNeg = $pct !== null && $pct < 0;
                            $label = $pct === null ? '—' : ($isNeg ? '⚠️ EPS Earnings Miss' : '🚀 EPS Earnings Beat');
                            $pctClass = $isNeg ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400';
                        @endphp
                        <td class="whitespace-nowrap text-xs {{ $pctClass }}">{{ $label }}</td>
                        <td class="text-right font-semibold {{ $pctClass }}">
                            @if ($pct !== null)
                                {{ ($pct >= 0 ? '+' : '') . number_format($pct, 2) }}%
                            @else — @endif
                        </td>
                        <td class="text-right">
                            @if ($event->revenue_surprise_percent !== null)
                                {{ number_format((float) $event->revenue_surprise_percent, 2) }}%
                            @else — @endif
                        </td>
                        <td class="text-right">
                            {{ $event->relative_volume !== null
                                ? number_format((float) $event->relative_volume, 2) : '—' }}
                        </td>
                        <td class="text-right">{{ optional($event->alerts->first())->score ?? '—' }}</td>
                        <td>
                            @if ($watched->contains(strtoupper($event->symbol)))
                                <span class="text-xs text-zinc-500">{{ __('Already watched') }}</span>
                            @else
                                <button type="button"
                                        wire:click="addToWatchlist({{ $event->id }})"
                                        class="da-btn-primary text-xs">
                                    {{ __('Add to Watchlist') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13" class="text-center text-sm text-zinc-500 py-6">
                            {{ __('No qualifying earnings events yet.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-4">
            {{ $events->links() }}
        </div>
    </div>
</div>
