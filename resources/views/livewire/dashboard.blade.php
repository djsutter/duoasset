<div class="flex h-full w-full flex-1 flex-col gap-5">
    @if ($viewMode === 'crypto')
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="h-[230px] relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="h-[230px] relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="h-[230px] relative overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <livewire:valuation-progress />
            </div>
        </div>
    @elseif ($viewMode === 'futures')
        {{-- Futures Mode Dashboard --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-1 border-b border-zinc-200/70 dark:border-zinc-800/80" data-testid="futures-dashboard">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    {{ __('Futures Overview') }}
                </h1>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ __('Your three most recently visited commodity futures contracts with live market data observations.') }}
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <button type="button"
                        wire:click="refreshFuturesQuotes"
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300 font-medium transition-colors"
                        data-testid="refresh-futures-quotes-btn">
                    <flux:icon icon="arrow-path" variant="mini" class="size-3.5" />
                    <span>{{ __('Refresh Quotes') }}</span>
                </button>
            </div>
        </div>

        <div class="p-5 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xs" data-testid="recent-futures-card">
            <div class="flex items-center justify-between gap-2 pb-3 mb-4 border-b border-zinc-100 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <div class="p-1.5 rounded-lg bg-amber-50 dark:bg-amber-950/70 text-amber-600 dark:text-amber-400">
                        <flux:icon icon="presentation-chart-line" variant="mini" class="size-4" />
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Recently Visited Futures') }}
                        </h3>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                            {{ __('Last 3 unique futures contracts visited in your session') }}
                        </p>
                    </div>
                </div>
            </div>

            @if (($recentFutures ?? collect())->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse" data-testid="recent-futures-table">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-800 text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                <th class="py-2.5 px-3">{{ __('Instrument') }}</th>
                                <th class="py-2.5 px-3 text-right">{{ __('Latest price') }}</th>
                                <th class="py-2.5 px-3">{{ __('Currency') }}</th>
                                <th class="py-2.5 px-3">{{ __('Quote time') }}</th>
                                <th class="py-2.5 px-3 text-right">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/80 text-sm">
                            @foreach ($recentFutures as $item)
                                @php
                                    $freshness = $item['freshness'] ?? ['status' => 'unavailable', 'label' => 'Unavailable', 'badge_color' => 'red'];
                                    $badgeClasses = match ($freshness['status'] ?? 'unavailable') {
                                        'live' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300 ring-emerald-300 dark:ring-emerald-800',
                                        'delayed' => 'bg-amber-50 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300 ring-amber-300 dark:ring-amber-800',
                                        'stale' => 'bg-orange-50 text-orange-800 dark:bg-orange-950/70 dark:text-orange-300 ring-orange-300 dark:ring-orange-800',
                                        'market_closed' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 ring-zinc-300 dark:ring-zinc-700',
                                        default => 'bg-rose-50 text-rose-700 dark:bg-rose-950/70 dark:text-rose-300 ring-rose-300 dark:ring-rose-800',
                                    };
                                @endphp
                                <tr class="hover:bg-zinc-50/80 dark:hover:bg-zinc-800/40 transition-colors" data-testid="recent-futures-row-{{ strtolower($item['symbol']) }}">
                                    <td class="py-3 px-3">
                                        <a href="{{ $item['url'] }}" class="group inline-flex flex-col" data-testid="recent-futures-link-{{ strtolower($item['symbol']) }}">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono font-bold text-zinc-900 dark:text-white group-hover:text-amber-600 dark:group-hover:text-amber-400 transition-colors">
                                                    {{ $item['symbol'] }}
                                                </span>
                                                <span class="text-zinc-400 dark:text-zinc-500">—</span>
                                                <span class="font-medium text-zinc-700 dark:text-zinc-200 group-hover:underline">
                                                    {{ $item['name'] }}
                                                </span>
                                            </div>
                                            <div class="flex items-center gap-2 text-[11px] text-zinc-500 dark:text-zinc-400 mt-0.5">
                                                <span>{{ $item['exchange'] }}</span>
                                                @if (! empty($item['price_unit']))
                                                    <span>•</span>
                                                    <span>{{ $item['price_unit'] }}</span>
                                                @endif
                                                @if (! empty($item['front_contract']))
                                                    <span>•</span>
                                                    <span>{{ __('Front:') }} {{ $item['front_contract'] }}</span>
                                                @endif
                                            </div>
                                        </a>
                                    </td>
                                    <td class="py-3 px-3 text-right font-mono" data-testid="recent-futures-price-{{ strtolower($item['symbol']) }}">
                                        @if ($item['formatted_price'] !== null)
                                            <div class="font-bold text-zinc-900 dark:text-white">
                                                {{ $item['formatted_price'] }}
                                            </div>
                                            @if ($item['daily_change_percent'] !== null)
                                                @php
                                                    $chg = (float) $item['daily_change_percent'];
                                                    $pos = $chg >= 0;
                                                @endphp
                                                <div class="text-[11px] {{ $pos ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                                    {{ $pos ? '+' : '' }}{{ number_format($chg, 2) }}%
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500 italic">{{ __('Unavailable') }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 font-mono text-xs text-zinc-700 dark:text-zinc-300" data-testid="recent-futures-currency-{{ strtolower($item['symbol']) }}">
                                        {{ $item['currency'] }}
                                    </td>
                                    <td class="py-3 px-3 font-mono text-xs text-zinc-600 dark:text-zinc-400" data-testid="recent-futures-time-{{ strtolower($item['symbol']) }}">
                                        @if (! empty($item['quote_time_formatted']))
                                            <span title="{{ $item['quote_datetime_formatted'] }}">{{ $item['quote_time_formatted'] }}</span>
                                        @else
                                            <span class="text-zinc-400 dark:text-zinc-500">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 ring-inset {{ $badgeClasses }}"
                                              data-testid="recent-futures-freshness-{{ strtolower($item['symbol']) }}">
                                            {{ $freshness['label'] }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="py-8 px-4 text-center rounded-xl border border-dashed border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/40" data-testid="futures-empty-history">
                    <p class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                        {{ __('No recently visited futures instruments yet.') }}
                    </p>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1 max-w-md mx-auto">
                        {{ __('Open any supported commodity futures instrument below to view its interactive Vela chart and news timeline. Your last three visited contracts will appear here automatically.') }}
                    </p>
                </div>
            @endif

            @if (! empty($supportedFutures))
                <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex flex-wrap items-center gap-2" data-testid="supported-futures-links">
                    <span class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mr-1">
                        {{ __('Supported Futures:') }}
                    </span>
                    @foreach ($supportedFutures as $inst)
                        <a href="{{ $inst['url'] }}"
                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/60 hover:bg-amber-50 hover:border-amber-300 dark:hover:bg-amber-950/40 dark:hover:border-amber-800 text-zinc-700 dark:text-zinc-300 transition-colors"
                           data-testid="supported-futures-link-{{ $inst['route_symbol'] }}">
                            <span class="font-mono font-bold text-zinc-900 dark:text-white">{{ $inst['symbol'] }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $inst['name'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        {{-- Stock Mode Dashboard --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 pb-1 border-b border-zinc-200/70 dark:border-zinc-800/80">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    {{ __('Market Overview') }}
                </h1>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                    {{ __('Key watchlist metrics, 24-hour money flow movers, and earnings surprise leaders.') }}
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <a href="{{ route('watchlists.index') }}" wire:navigate class="px-2.5 py-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300 font-medium transition-colors">
                    {{ __('Watchlists') }}
                </a>
                <a href="{{ route('money-flows.index') }}" wire:navigate class="px-2.5 py-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300 font-medium transition-colors">
                    {{ __('Money Flows') }}
                </a>
                <a href="{{ route('watchlists.earnings-surprises') }}" wire:navigate class="px-2.5 py-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 hover:bg-zinc-50 dark:hover:bg-zinc-800 text-zinc-700 dark:text-zinc-300 font-medium transition-colors">
                    {{ __('EPS Surprises') }}
                </a>
            </div>
        </div>

        {{-- Setups & Watchlist Gainers --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            {{-- Card 1: Top Scoring Setups (market-wide, latest score per setup type) --}}
            <div class="p-5 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xs" data-testid="top-scoring-setups-card">
                <div class="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <div class="p-1.5 rounded-lg bg-sky-50 dark:bg-sky-950/70 text-sky-600 dark:text-sky-400">
                            <flux:icon icon="chart-bar" variant="mini" class="size-4" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ __('The Top Scoring Setups') }}
                            </h3>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                {{ __('Latest highest scoring setup per stock across the market') }}
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('watchlists.stock-buy-setups') }}" wire:navigate class="text-xs text-sky-600 dark:text-sky-400 hover:underline inline-flex items-center gap-1 font-medium">
                        <span>{{ __('All Setups') }}</span>
                        <flux:icon icon="chevron-right" variant="micro" class="size-3" />
                    </a>
                </div>

                @if ($highestScoringStocks->isNotEmpty())
                    <div class="space-y-2.5">
                        @foreach ($highestScoringStocks as $item)
                            <div class="flex items-center justify-between p-3 rounded-lg bg-zinc-50/70 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-800 hover:border-zinc-200 dark:hover:border-zinc-700 transition-colors">
                                <div class="min-w-0 pr-2">
                                    <div class="flex items-center gap-2">
                                        <x-ticker-link :symbol="$item['symbol']" class="text-sm" />
                                        @if (! empty($item['setup_type']))
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium uppercase tracking-wider bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400">
                                                {{ str_replace('_', ' ', $item['setup_type']) }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">
                                        {{ $item['company_name'] }}
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold font-mono bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300 ring-1 ring-inset ring-sky-300 dark:ring-sky-800">
                                        {{ __('Score') }}: {{ $item['setup_score'] }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('No setup scores recorded yet.') }}
                    </div>
                @endif
            </div>

            {{-- Card 2: Top Watchlist Gainers (or empty-watchlist notice) --}}
            @if (! $hasWatchlistItems)
                <div class="p-4 rounded-xl border border-sky-200 dark:border-sky-800/60 bg-sky-50 dark:bg-sky-950/40 text-sky-800 dark:text-sky-200 flex items-start gap-3.5 shadow-xs" data-testid="watchlist-empty-banner">
                    <div class="p-2 rounded-lg bg-sky-100 dark:bg-sky-900/50 text-sky-600 dark:text-sky-400 shrink-0">
                        <flux:icon icon="information-circle" class="size-5" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-semibold text-sm text-zinc-900 dark:text-zinc-100">
                            {{ __('Your watchlist is currently empty or does not exist.') }}
                        </p>
                        <p class="text-xs text-zinc-600 dark:text-zinc-400 mt-1">
                            {{ __('Add stocks to your watchlist to monitor your highest scoring setup opportunities and percentage gainers since added date.') }}
                        </p>
                        <div class="mt-2.5">
                            <a href="{{ route('watchlists.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-xs font-semibold text-sky-600 dark:text-sky-400 hover:text-sky-700 dark:hover:text-sky-300 underline hover:no-underline">
                                <span>{{ __('Manage Watchlists') }}</span>
                                <flux:icon icon="arrow-right" variant="mini" class="size-3.5" />
                            </a>
                        </div>
                    </div>
                </div>
            @else
                <div class="p-5 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xs" data-testid="top-watchlist-gainers-card">
                    <div class="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-zinc-100 dark:border-zinc-800">
                        <div class="flex items-center gap-2">
                            <div class="p-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/70 text-emerald-600 dark:text-emerald-400">
                                <flux:icon icon="arrow-trending-up" variant="mini" class="size-4" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ __('Top Watchlist Gainers') }}
                                </h3>
                                <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                    {{ __('Highest percentage gains since date added to watchlist') }}
                                </p>
                            </div>
                        </div>
                        <a href="{{ route('watchlists.index') }}" wire:navigate class="text-xs text-sky-600 dark:text-sky-400 hover:underline inline-flex items-center gap-1 font-medium">
                            <span>{{ __('Watchlist') }}</span>
                            <flux:icon icon="chevron-right" variant="micro" class="size-3" />
                        </a>
                    </div>

                    @if ($highestGainingStocks->isNotEmpty())
                        <div class="space-y-2.5">
                            @foreach ($highestGainingStocks as $item)
                                <div class="flex items-center justify-between p-3 rounded-lg bg-zinc-50/70 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-800 hover:border-zinc-200 dark:hover:border-zinc-700 transition-colors">
                                    <div class="min-w-0 pr-2">
                                        <div class="flex items-center gap-2">
                                            <x-ticker-link :symbol="$item['symbol']" class="text-sm" />
                                            <span class="text-[11px] text-zinc-400 dark:text-zinc-500">
                                                {{ __('Added :date', ['date' => $item['added_at']?->format('M j, Y')]) }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate mt-0.5">
                                            {{ $item['company_name'] }}
                                        </p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        @php
                                            $gain = (float) $item['gain_pct'];
                                            $isPositive = $gain >= 0;
                                        @endphp
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold font-mono {{ $isPositive ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300 ring-1 ring-inset ring-emerald-300 dark:ring-emerald-800' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/70 dark:text-rose-300 ring-1 ring-inset ring-rose-300 dark:ring-rose-800' }}">
                                            {{ $isPositive ? '+' : '' }}{{ number_format($gain, 2) }}%
                                        </span>
                                        <div class="text-[10px] text-zinc-400 dark:text-zinc-500 font-mono mt-0.5">
                                            ${{ number_format($item['entry_price'], 2) }} &rarr; ${{ number_format($item['latest_price'], 2) }}
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('No price gain data available for items in this watchlist yet.') }}
                        </div>
                    @endif
                </div>
            @endif
        </div>


        {{-- Market Movers: Money Flow Gainers, Money Flow Losers, Highest EPS Surprises --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            {{-- Card 3: Top 2 Money Flow Gainers in Past 24h --}}
            <div class="p-5 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xs" data-testid="money-flow-gainers-card">
                <div class="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block size-2 rounded-full bg-emerald-500"></span>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ __('Money Flow Gainers') }}
                            </h3>
                        </div>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('Past 24 hours (Sector ETF proxy)') }}
                        </p>
                    </div>
                    <a href="{{ route('money-flows.index') }}" wire:navigate class="text-xs text-sky-600 dark:text-sky-400 hover:underline inline-flex items-center gap-1 font-medium">
                        <span>{{ __('Flows') }}</span>
                        <flux:icon icon="chevron-right" variant="micro" class="size-3" />
                    </a>
                </div>

                @if ($moneyFlowGainers->isNotEmpty())
                    <div class="space-y-2.5">
                        @foreach ($moneyFlowGainers as $snapshot)
                            @php
                                $val = (float) ($snapshot->daily_change_pct ?? $snapshot->hourly_change_pct ?? 0);
                            @endphp
                            <div class="flex items-center justify-between p-2.5 rounded-lg bg-zinc-50/70 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-800">
                                <div class="min-w-0 pr-2">
                                    <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-200 block truncate">
                                        {{ $snapshot->label ?: ucfirst($snapshot->sector) }}
                                    </span>
                                    @if ($snapshot->direction)
                                        <span class="text-[10px] text-zinc-500 dark:text-zinc-400 capitalize">
                                            {{ $snapshot->direction }}
                                        </span>
                                    @endif
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold font-mono bg-emerald-50 text-emerald-700 dark:bg-emerald-950/70 dark:text-emerald-300 ring-1 ring-inset ring-emerald-300 dark:ring-emerald-800">
                                        +{{ number_format($val, 2) }}%
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('No money flow data available yet.') }}
                    </div>
                @endif
            </div>

            {{-- Card 4: Top 2 Money Flow Losers in Past 24h --}}
            <div class="p-5 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xs" data-testid="money-flow-losers-card">
                <div class="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <div class="flex items-center gap-1.5">
                            <span class="inline-block size-2 rounded-full bg-rose-500"></span>
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ __('Money Flow Losers') }}
                            </h3>
                        </div>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('Past 24 hours (Sector ETF proxy)') }}
                        </p>
                    </div>
                    <a href="{{ route('money-flows.index') }}" wire:navigate class="text-xs text-sky-600 dark:text-sky-400 hover:underline inline-flex items-center gap-1 font-medium">
                        <span>{{ __('Flows') }}</span>
                        <flux:icon icon="chevron-right" variant="micro" class="size-3" />
                    </a>
                </div>

                @if ($moneyFlowLosers->isNotEmpty())
                    <div class="space-y-2.5">
                        @foreach ($moneyFlowLosers as $snapshot)
                            @php
                                $val = (float) ($snapshot->daily_change_pct ?? $snapshot->hourly_change_pct ?? 0);
                            @endphp
                            <div class="flex items-center justify-between p-2.5 rounded-lg bg-zinc-50/70 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-800">
                                <div class="min-w-0 pr-2">
                                    <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-200 block truncate">
                                        {{ $snapshot->label ?: ucfirst($snapshot->sector) }}
                                    </span>
                                    @if ($snapshot->direction)
                                        <span class="text-[10px] text-zinc-500 dark:text-zinc-400 capitalize">
                                            {{ $snapshot->direction }}
                                        </span>
                                    @endif
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold font-mono bg-rose-50 text-rose-700 dark:bg-rose-950/70 dark:text-rose-300 ring-1 ring-inset ring-rose-300 dark:ring-rose-800">
                                        {{ number_format($val, 2) }}%
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('No money flow data available yet.') }}
                    </div>
                @endif
            </div>

            {{-- Card 5: Top 2 Highest EPS Surprise Stocks --}}
            <div class="p-5 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xs" data-testid="highest-eps-surprises-card">
                <div class="flex items-center justify-between gap-2 pb-3 mb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <div class="flex items-center gap-1.5">
                            <flux:icon icon="bolt" variant="micro" class="size-3.5 text-amber-500" />
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ __('Highest EPS Surprises') }}
                            </h3>
                        </div>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('Top earnings surprise beats') }}
                        </p>
                    </div>
                    <a href="{{ route('watchlists.earnings-surprises') }}" wire:navigate class="text-xs text-sky-600 dark:text-sky-400 hover:underline inline-flex items-center gap-1 font-medium">
                        <span>{{ __('Surprises') }}</span>
                        <flux:icon icon="chevron-right" variant="micro" class="size-3" />
                    </a>
                </div>

                @if ($highestEpsSurprises->isNotEmpty())
                    <div class="space-y-2.5">
                        @foreach ($highestEpsSurprises as $event)
                            @php
                                $surprise = (float) $event->eps_surprise_percent;
                                $isPositive = $surprise >= 0;
                            @endphp
                            <div class="flex items-center justify-between p-2.5 rounded-lg bg-zinc-50/70 dark:bg-zinc-800/40 border border-zinc-100 dark:border-zinc-800">
                                <div class="min-w-0 pr-2">
                                    <div class="flex items-center gap-2">
                                        <x-ticker-link :symbol="$event->symbol" class="text-xs" />
                                        @if ($event->report_date)
                                            <span class="text-[10px] text-zinc-400 dark:text-zinc-500">
                                                {{ $event->report_date->format('M j') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-[11px] text-zinc-500 dark:text-zinc-400 truncate mt-0.5">
                                        {{ $event->company_name }}
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold font-mono {{ $isPositive ? 'bg-amber-50 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300 ring-1 ring-inset ring-amber-300 dark:ring-amber-800' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/70 dark:text-rose-300 ring-1 ring-inset ring-rose-300 dark:ring-rose-800' }}">
                                        {{ $isPositive ? '+' : '' }}{{ number_format($surprise, 1) }}%
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="py-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('No earnings surprise data recorded yet.') }}
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
