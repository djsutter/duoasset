<div class="space-y-6" data-testid="stock-analysis-page">
    {{-- Page Header --}}
    <div class="da-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-xs">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            {{-- Ticker & Company details --}}
            <div class="space-y-1.5">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-3xl font-extrabold tracking-tight font-mono text-zinc-950 dark:text-white" data-testid="ticker-symbol">
                        {{ $symbol }}
                    </h1>
                    @if ($this->exchange)
                        <span class="rounded-md bg-zinc-100 dark:bg-zinc-800 px-2.5 py-0.5 text-xs font-semibold text-zinc-700 dark:text-zinc-300 uppercase tracking-wider" data-testid="stock-exchange">
                            {{ $this->exchange }}
                        </span>
                    @endif
                    @if ($priceType)
                        <span class="rounded-md bg-sky-50 dark:bg-sky-950/60 border border-sky-200 dark:border-sky-800 px-2.5 py-0.5 text-xs font-medium text-sky-700 dark:text-sky-300" data-testid="price-type-badge">
                            {{ $priceType }}
                        </span>
                    @endif
                </div>

                <div class="text-base font-medium text-zinc-600 dark:text-zinc-400" data-testid="company-name">
                    {{ $this->companyName ?? __('Company name unavailable') }}
                </div>
            </div>

            {{-- Price & Key Metrics --}}
            <div class="flex flex-wrap items-baseline lg:items-end gap-x-8 gap-y-3">
                {{-- Price & Change --}}
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        {{ __('Current Price') }}
                    </div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl lg:text-3xl font-bold font-mono text-zinc-950 dark:text-white" data-testid="stock-price">
                            @if ($this->currentPrice !== null)
                                ${{ number_format($this->currentPrice, 2) }}
                            @else
                                <span class="text-zinc-400 dark:text-zinc-600 text-xl font-normal">—</span>
                            @endif
                        </span>

                        @if ($this->priceChange !== null || $this->priceChangePercent !== null)
                            @php
                                $isPositive = ($this->priceChange ?? 0) > 0 || ($this->priceChangePercent ?? 0) > 0;
                                $isNegative = ($this->priceChange ?? 0) < 0 || ($this->priceChangePercent ?? 0) < 0;
                                $colorClass = $isPositive ? 'text-emerald-600 dark:text-emerald-400' : ($isNegative ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-500');
                            @endphp
                            <span class="text-sm font-semibold font-mono {{ $colorClass }}" data-testid="stock-change">
                                @if ($this->priceChange !== null)
                                    {{ $this->priceChange > 0 ? '+' : '' }}{{ number_format($this->priceChange, 2) }}
                                @endif
                                @if ($this->priceChangePercent !== null)
                                    ({{ $this->priceChangePercent > 0 ? '+' : '' }}{{ number_format($this->priceChangePercent, 2) }}%)
                                @endif
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Volume --}}
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        {{ __('Volume') }}
                    </div>
                    <div class="text-base font-bold font-mono text-zinc-900 dark:text-zinc-100" data-testid="stock-volume">
                        @if ($this->volume !== null)
                            {{ number_format($this->volume) }}
                        @else
                            <span class="text-zinc-400 dark:text-zinc-600 font-normal">—</span>
                        @endif
                    </div>
                </div>

                {{-- Market Cap --}}
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        {{ __('Market Cap') }}
                    </div>
                    <div class="text-base font-bold font-mono text-zinc-900 dark:text-zinc-100" data-testid="stock-market-cap">
                        @if ($this->marketCap !== null)
                            @if ($this->marketCap >= 1e12)
                                ${{ number_format($this->marketCap / 1e12, 2) }}T
                            @elseif ($this->marketCap >= 1e9)
                                ${{ number_format($this->marketCap / 1e9, 2) }}B
                            @elseif ($this->marketCap >= 1e6)
                                ${{ number_format($this->marketCap / 1e6, 2) }}M
                            @else
                                ${{ number_format($this->marketCap) }}
                            @endif
                        @else
                            <span class="text-zinc-400 dark:text-zinc-600 font-normal">—</span>
                        @endif
                    </div>
                </div>

                {{-- Timestamp --}}
                <div class="space-y-1">
                    <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        {{ __('Data Timestamp') }}
                    </div>
                    <div class="text-xs font-mono text-zinc-600 dark:text-zinc-400" data-testid="stock-timestamp">
                        {{ $this->marketDataTimestamp ?? '—' }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Main Chart Section --}}
    <div class="da-card p-0 overflow-hidden bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-xs"
         x-data="{
             symbol: @js($symbol),
             userId: @js((string)(auth()->id() ?? 'guest')),
             candles: @js($candles),
             workspace: null,
             isFullscreen: false,
             isLoading: false,
             errorMessage: @js($candleError ?? null),
             fetchRequestId: 0,
             activeAbortController: null,
             selectedInterval: '1D',
             selectedRange: '1Y',
             intervals: [
                 { id: '1m', label: '1m', api: '1min', vela: '1m' },
                 { id: '5m', label: '5m', api: '5min', vela: '5m' },
                 { id: '15m', label: '15m', api: '15min', vela: '15m' },
                 { id: '30m', label: '30m', api: '30min', vela: '30m' },
                 { id: '1h', label: '1h', api: '1hour', vela: '1h' },
                 { id: '1D', label: '1D', api: '1day', vela: '1D' },
                 { id: '1W', label: '1W', api: '1week', vela: '1W' },
                 { id: '1M', label: '1M', api: '1month', vela: '1M' },
             ],
             ranges: ['1M', '3M', '6M', '1Y', '5Y', 'All'],
             initChart() {
                 // Check localStorage for saved user preferences
                 try {
                     const key = 'duoasset_chart_prefs_' + this.userId + '_' + this.symbol;
                     const saved = JSON.parse(localStorage.getItem(key));
                     if (saved && saved.interval && this.intervals.some(i => i.id === saved.interval)) {
                         this.selectedInterval = saved.interval;
                     }
                     if (saved && saved.range && this.ranges.includes(saved.range)) {
                         this.selectedRange = saved.range;
                     }
                 } catch (e) {
                     // ignore storage errors
                 }

                 let attempts = 0;
                 const maxAttempts = 25; // 25 * 200ms = 5s timeout
                 const attempt = () => {
                     if (window.initStockChart) {
                         if (this.selectedInterval === '1D' && this.selectedRange === '1Y') {
                             this.renderChart();
                         } else {
                             this.fetchCandles();
                         }
                     } else if (attempts < maxAttempts) {
                         attempts++;
                         setTimeout(attempt, 200);
                     } else {
                         this.isLoading = false;
                         this.errorMessage = '{{ __('Chart engine failed to load. Please refresh the page.') }}';
                     }
                 };
                 this.$nextTick(attempt);
             },
             renderChart() {
                 if (!window.initStockChart) {
                     this.errorMessage = '{{ __('Chart engine is not available.') }}';
                     return;
                 }
                 this.errorMessage = null;
                 const activeItem = this.intervals.find(i => i.id === this.selectedInterval) || { vela: '1D' };
                 const persistKey = 'duoasset_vela_' + this.userId + '_' + this.symbol;
                 try {
                     const mountTarget = this.$refs.velaMount || this.$refs.chartContainer;
                     this.workspace = window.initStockChart(mountTarget, {
                         symbol: this.symbol,
                         candles: this.candles,
                         timeframe: activeItem.vela,
                         persistKey: persistKey,
                         previousWorkspace: this.workspace,
                     });
                     if (this.candles.length > 0 && !this.workspace) {
                         this.errorMessage = '{{ __('Unable to initialize financial chart display.') }}';
                     }
                 } catch (e) {
                     console.error('Failed to render chart:', e);
                     this.errorMessage = '{{ __('Chart initialization error. Please select another interval or retry.') }}';
                 }
             },
             savePreferences() {
                 try {
                     const key = 'duoasset_chart_prefs_' + this.userId + '_' + this.symbol;
                     localStorage.setItem(key, JSON.stringify({
                         interval: this.selectedInterval,
                         range: this.selectedRange,
                     }));
                 } catch (e) {
                     // ignore
                 }
             },
             changeInterval(intervalId) {
                 if (this.selectedInterval === intervalId && !this.errorMessage) return;
                 this.selectedInterval = intervalId;

                 // Sensible range adjustments for intraday intervals
                 if (['1m', '5m'].includes(intervalId)) {
                     if (['5Y', 'All', '1Y'].includes(this.selectedRange)) {
                         this.selectedRange = '1M';
                     }
                 } else if (['15m', '30m'].includes(intervalId)) {
                     if (['5Y', 'All'].includes(this.selectedRange)) {
                         this.selectedRange = '3M';
                     }
                 } else if (intervalId === '1h') {
                     if (this.selectedRange === 'All') {
                         this.selectedRange = '6M';
                     }
                 } else if (['1W', '1M'].includes(intervalId)) {
                     if (this.selectedRange === '1M') {
                         this.selectedRange = '1Y';
                     }
                 }

                 this.savePreferences();
                 this.fetchCandles();
             },
             changeRange(rangeId) {
                 if (this.selectedRange === rangeId && !this.errorMessage) return;
                 this.selectedRange = rangeId;

                 // If choosing wide ranges with high-frequency intraday, adjust to daily
                 if (['5Y', 'All'].includes(rangeId) && ['1m', '5m', '15m', '30m'].includes(this.selectedInterval)) {
                     this.selectedInterval = '1D';
                 }

                 this.savePreferences();
                 this.fetchCandles();
             },
             async fetchCandles() {
                 if (this.activeAbortController) {
                     try { this.activeAbortController.abort(); } catch (e) {}
                 }
                 this.activeAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
                 const currentRequestId = ++this.fetchRequestId;

                 this.isLoading = true;
                 this.errorMessage = null;

                 const toDate = new Date();
                 const fromDate = new Date();

                 switch (this.selectedRange) {
                     case '1M':
                         fromDate.setDate(fromDate.getDate() - 30);
                         break;
                     case '3M':
                         fromDate.setDate(fromDate.getDate() - 90);
                         break;
                     case '6M':
                         fromDate.setDate(fromDate.getDate() - 180);
                         break;
                     case '1Y':
                         fromDate.setDate(fromDate.getDate() - 365);
                         break;
                     case '5Y':
                         fromDate.setFullYear(fromDate.getFullYear() - 5);
                         break;
                     case 'All':
                         fromDate.setFullYear(fromDate.getFullYear() - 20);
                         break;
                     default:
                         fromDate.setDate(fromDate.getDate() - 365);
                 }

                 const fromStr = fromDate.toISOString().slice(0, 10);
                 const toStr = toDate.toISOString().slice(0, 10);
                 const activeItem = this.intervals.find(i => i.id === this.selectedInterval) || { api: '1day' };

                 const url = `/stocks/${encodeURIComponent(this.symbol)}/candles?interval=${activeItem.api}&from=${fromStr}&to=${toStr}&range=${this.selectedRange}`;

                 try {
                     const response = await fetch(url, {
                         signal: this.activeAbortController?.signal,
                         headers: {
                             'Accept': 'application/json',
                             'X-Requested-With': 'XMLHttpRequest',
                         }
                     });

                     if (currentRequestId !== this.fetchRequestId) return;

                     if (!response.ok) {
                         let errMsg = `HTTP ${response.status}`;
                         try {
                             const errJson = await response.json();
                             if (errJson && errJson.message) {
                                 errMsg = errJson.message;
                             }
                         } catch (e) {}
                         throw new Error(errMsg);
                     }

                     const data = await response.json();
                     if (currentRequestId !== this.fetchRequestId) return;

                     this.candles = Array.isArray(data.candles) ? data.candles : [];
                     this.renderChart();
                 } catch (err) {
                     if (err.name === 'AbortError') return;
                     if (currentRequestId !== this.fetchRequestId) return;

                     console.error('Error fetching candles:', err);
                     this.errorMessage = err?.message || '{{ __('Unable to load historical candles. Please select another interval or retry.') }}';
                     this.candles = [];
                     if (this.workspace && typeof this.workspace.destroy === 'function') {
                         try { this.workspace.destroy(); } catch (e) {}
                         this.workspace = null;
                     }
                     if (this.$refs.velaMount) {
                         this.$refs.velaMount.innerHTML = '';
                     }
                 } finally {
                     if (currentRequestId === this.fetchRequestId) {
                         this.isLoading = false;
                     }
                 }
             },
             toggleFullscreen() {
                 this.isFullscreen = !this.isFullscreen;
                 if (this.isFullscreen) {
                     this.$refs.chartWrapper.requestFullscreen?.();
                 } else if (document.fullscreenElement) {
                     document.exitFullscreen?.();
                 }
                 setTimeout(() => this.workspace?.resize?.(), 100);
             }
         }"
         x-init="
             initChart();
             if (typeof $cleanup === 'function') {
                 $cleanup(() => {
                     if (workspace && typeof workspace.destroy === 'function') {
                         try { workspace.destroy(); } catch (e) {}
                         workspace = null;
                     }
                 });
             }
         "
         x-ref="chartWrapper"
         :class="{ 'fixed inset-0 z-50 bg-zinc-950 p-4': isFullscreen }"
         data-testid="main-chart-wrapper">

        {{-- Chart Controls Bar --}}
        <div class="flex flex-wrap items-center justify-between gap-y-2 gap-x-4 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50/70 dark:bg-zinc-900/50">
            {{-- Left: Symbol badge & Timeframe subtitle & Interval selectors --}}
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-xs font-bold uppercase tracking-wider text-zinc-950 dark:text-white" data-testid="chart-symbol-badge">
                        {{ $symbol }}
                    </span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400 font-mono hidden sm:inline"
                          x-text="'(' + selectedInterval + ' • ' + selectedRange + ')'">
                    </span>
                </div>

                <div class="h-4 w-px bg-zinc-200 dark:bg-zinc-800 hidden sm:block"></div>

                {{-- Intervals --}}
                <div class="flex items-center gap-1" data-testid="interval-controls">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mr-0.5 hidden md:inline">
                        {{ __('Interval') }}:
                    </span>

                    {{-- Intraday Group --}}
                    <div class="inline-flex rounded-md p-0.5 bg-zinc-200/50 dark:bg-zinc-800/60" data-testid="intraday-intervals">
                        @foreach ([
                            ['id' => '1m', 'label' => '1m'],
                            ['id' => '5m', 'label' => '5m'],
                            ['id' => '15m', 'label' => '15m'],
                            ['id' => '30m', 'label' => '30m'],
                            ['id' => '1h', 'label' => '1h'],
                        ] as $item)
                            <button type="button"
                                    x-on:click="changeInterval('{{ $item['id'] }}')"
                                    :class="selectedInterval === '{{ $item['id'] }}' ? 'bg-white dark:bg-zinc-700 text-zinc-950 dark:text-white shadow-xs font-semibold' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-200 font-medium'"
                                    class="rounded px-2 py-0.5 text-xs font-mono transition-colors"
                                    title="{{ $item['id'] }} interval"
                                    data-testid="interval-btn-{{ $item['id'] }}">
                                {{ $item['label'] }}
                            </button>
                        @endforeach
                    </div>

                    {{-- Daily / Longer Group --}}
                    <div class="inline-flex rounded-md p-0.5 bg-zinc-200/50 dark:bg-zinc-800/60 ml-1" data-testid="daily-intervals">
                        @foreach ([
                            ['id' => '1D', 'label' => '1D'],
                            ['id' => '1W', 'label' => '1W'],
                            ['id' => '1M', 'label' => '1M'],
                        ] as $item)
                            <button type="button"
                                    x-on:click="changeInterval('{{ $item['id'] }}')"
                                    :class="selectedInterval === '{{ $item['id'] }}' ? 'bg-white dark:bg-zinc-700 text-zinc-950 dark:text-white shadow-xs font-semibold' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-200 font-medium'"
                                    class="rounded px-2 py-0.5 text-xs font-mono transition-colors"
                                    title="{{ $item['id'] }} interval"
                                    data-testid="interval-btn-{{ $item['id'] }}">
                                {{ $item['label'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Right: Range buttons, Loading indicator & Fullscreen --}}
            <div class="flex items-center gap-2 sm:gap-3 ml-auto">
                {{-- Range Presets --}}
                <div class="flex items-center gap-1" data-testid="range-controls">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mr-0.5 hidden lg:inline">
                        {{ __('Range') }}:
                    </span>
                    <div class="inline-flex rounded-md p-0.5 bg-zinc-200/50 dark:bg-zinc-800/60">
                        @foreach (['1M', '3M', '6M', '1Y', '5Y', 'All'] as $rng)
                            <button type="button"
                                    x-on:click="changeRange('{{ $rng }}')"
                                    :class="selectedRange === '{{ $rng }}' ? 'bg-white dark:bg-zinc-700 text-zinc-950 dark:text-white shadow-xs font-semibold' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-200 font-medium'"
                                    class="rounded px-2 py-0.5 text-xs font-mono transition-colors"
                                    title="{{ $rng }} history"
                                    data-testid="range-btn-{{ $rng }}">
                                {{ $rng }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Loading Indicator --}}
                <div x-show="isLoading"
                     x-cloak
                     class="flex items-center gap-1.5 text-xs text-sky-600 dark:text-sky-400 font-medium animate-pulse"
                     data-testid="chart-loading-indicator">
                    <flux:icon icon="arrow-path" variant="mini" class="size-3.5 animate-spin" />
                    <span class="hidden xl:inline">{{ __('Loading...') }}</span>
                </div>

                {{-- Fullscreen button --}}
                <button type="button"
                        x-on:click="toggleFullscreen"
                        class="da-btn da-btn-secondary text-xs px-2.5 py-1 gap-1"
                        title="{{ __('Expand Chart') }}"
                        data-testid="chart-fullscreen-btn">
                    <flux:icon icon="arrows-pointing-out" variant="mini" class="size-3.5" />
                    <span x-text="isFullscreen ? '{{ __('Exit Fullscreen') }}' : '{{ __('Expanded View') }}'"></span>
                </button>
            </div>
        </div>

        {{-- Vela Chart Container --}}
        <div class="relative w-full" style="min-height: 540px; height: 580px;" x-ref="chartContainer" data-testid="vela-chart-container">
            {{-- Dedicated Vela mount surface separated from overlay states --}}
            <div x-ref="velaMount" data-vela-mount class="w-full h-full"></div>

            {{-- Loading Overlay --}}
            <div x-show="isLoading"
                 x-cloak
                 class="absolute inset-0 z-20 flex flex-col items-center justify-center bg-white/75 dark:bg-zinc-900/75 backdrop-blur-2xs">
                <flux:icon icon="arrow-path" variant="mini" class="size-8 text-sky-600 dark:text-sky-400 animate-spin" />
                <span class="mt-2 text-xs font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Updating chart data...') }}</span>
            </div>

            {{-- Error State --}}
            <div x-show="errorMessage && !isLoading"
                 x-cloak
                 class="absolute inset-0 z-10 flex flex-col items-center justify-center p-6 text-center text-rose-600 dark:text-rose-400"
                 data-testid="chart-error-state">
                <flux:icon icon="exclamation-triangle" variant="mini" class="size-10 mb-2 opacity-80" />
                <p class="font-semibold text-sm" x-text="errorMessage"></p>
                <button type="button"
                        x-on:click="fetchCandles()"
                        class="mt-3 da-btn da-btn-secondary text-xs px-3 py-1">
                    {{ __('Retry') }}
                </button>
            </div>

            {{-- Empty State --}}
            <div x-show="candles.length === 0 && !isLoading && !errorMessage"
                 x-cloak
                 class="absolute inset-0 flex flex-col items-center justify-center p-6 text-center text-zinc-500 dark:text-zinc-400"
                 data-testid="chart-empty-state">
                <flux:icon icon="chart-bar" variant="mini" class="size-10 mb-2 opacity-40" />
                <p class="font-semibold text-sm">{{ __('No historical candlestick data available') }}</p>
                <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-1 max-w-sm">
                    <span x-text="'No historical bars returned for ' + symbol + ' (' + selectedInterval + ' • ' + selectedRange + ').'"></span>
                    {{ __('Try selecting a different interval or a wider historical range.') }}
                </p>
            </div>
        </div>
    </div>

    {{-- DuoAsset Setup Score Panel --}}
    <div class="da-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-xs" data-testid="setup-score-panel">
        <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-4">
            <div class="flex items-center gap-3">
                <div class="p-2 rounded-lg bg-sky-100 dark:bg-sky-950/80 text-sky-600 dark:text-sky-400">
                    <flux:icon icon="sparkles" variant="mini" class="size-5" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                        <span>{{ __('DuoAsset Setup Score') }}</span>
                    </h2>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Proprietary setup ranking derived from volatility contraction, volume dry-up, and fundamental acceleration.') }}
                    </p>
                </div>
            </div>

            @if ($latestSetupAlert)
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <div class="text-2xl font-black font-mono text-sky-600 dark:text-sky-400" data-testid="overall-setup-score">
                            {{ $latestSetupAlert->setup_score }}
                        </div>
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">
                            {{ __('Score (0-100)') }}
                        </div>
                    </div>
                </div>
            @endif
        </div>

        @if ($latestSetupAlert)
            <div class="mt-6 space-y-6">
                @if ($setupAlertsByType && $setupAlertsByType->count() > 1)
                    <div class="space-y-2" data-testid="multi-setup-types">
                        <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Detected Setup Types for Symbol') }}
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($setupAlertsByType as $alertItem)
                                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs font-mono {{ $alertItem->id === $latestSetupAlert->id ? 'border-sky-300 dark:border-sky-800 bg-sky-50 dark:bg-sky-950/40 text-sky-800 dark:text-sky-300 font-bold' : 'border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/40 text-zinc-700 dark:text-zinc-300' }}">
                                    <span>{{ $alertItem->setup_type }}</span>
                                    <span class="rounded bg-sky-100 dark:bg-sky-900 px-1.5 py-0.2 text-[10px] font-bold text-sky-700 dark:text-sky-300">{{ $alertItem->setup_score }}/100</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Setup Summary Row --}}
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Setup Classification') }}</div>
                        <div class="mt-1 font-semibold text-sm text-zinc-900 dark:text-white" data-testid="setup-type">
                            {{ $latestSetupAlert->setup_type }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Raw Score') }}</div>
                        <div class="mt-1 font-mono font-semibold text-sm text-zinc-900 dark:text-white">
                            {{ $latestSetupAlert->raw_setup_score ?? '—' }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Heartbeat') }}</div>
                        <div class="mt-1 font-mono font-semibold text-sm text-zinc-900 dark:text-white">
                            {{ $latestSetupAlert->heartbeat_score ?? '—' }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Base Duration') }}</div>
                        <div class="mt-1 font-mono font-semibold text-sm text-zinc-900 dark:text-white">
                            {{ $latestSetupAlert->base_duration_days ? $latestSetupAlert->base_duration_days . ' days' : '—' }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Range Compression') }}</div>
                        <div class="mt-1 font-mono font-semibold text-sm text-zinc-900 dark:text-white">
                            {{ $latestSetupAlert->range_compression_pct !== null ? number_format((float) $latestSetupAlert->range_compression_pct, 1) . '%' : '—' }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Calculated At') }}</div>
                        <div class="mt-1 text-xs text-zinc-700 dark:text-zinc-300">
                            {{ $latestSetupAlert->detected_at ? \Carbon\Carbon::parse($latestSetupAlert->detected_at)->toFormattedDateString() : '—' }}
                        </div>
                    </div>
                </div>

                {{-- Component Point Breakdown --}}
                @if (! empty($setupScoreBreakdown))
                    <div>
                        <h3 class="text-sm font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-3">
                            {{ __('Scoring Component Breakdown') }}
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach ($setupScoreBreakdown as $key => $component)
                                <div class="p-3 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/60 flex items-center justify-between">
                                    <div class="space-y-0.5">
                                        <div class="text-xs font-semibold text-zinc-800 dark:text-zinc-200">
                                            {{ $component['label'] ?? ucfirst(str_replace('_', ' ', $key)) }}
                                        </div>
                                        @if (isset($component['value']))
                                            <div class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                                {{ $component['value'] }}
                                            </div>
                                        @endif
                                    </div>

                                    <div class="text-right">
                                        <span class="font-mono text-sm font-bold text-sky-600 dark:text-sky-400">
                                            {{ $component['points'] ?? 0 }}
                                        </span>
                                        <span class="text-xs text-zinc-400">
                                            / {{ $component['max'] ?? 0 }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (! empty($latestSetupAlert->reason_summary))
                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 text-xs text-zinc-600 dark:text-zinc-400">
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Scoring Summary') }}:</span>
                        {{ $latestSetupAlert->reason_summary }}
                    </div>
                @endif
            </div>
        @else
            {{-- Clear unavailable / not-calculated state --}}
            <div class="py-12 text-center" data-testid="setup-score-unavailable">
                <div class="inline-flex p-3 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-400 mb-3">
                    <flux:icon icon="exclamation-circle" variant="mini" class="size-6" />
                </div>
                <h3 class="text-base font-semibold text-zinc-800 dark:text-zinc-200">
                    {{ __('Setup Score Unavailable') }}
                </h3>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 max-w-md mx-auto mt-1">
                    {{ __('No recent stock buy setup calculation has been recorded for :symbol. Setup scores are generated automatically during regular scanner execution runs.', ['symbol' => $symbol]) }}
                </p>
            </div>
        @endif
    </div>

    {{-- Load Stock Chart JS --}}
    @vite(['resources/js/stock-chart.js'])
</div>
