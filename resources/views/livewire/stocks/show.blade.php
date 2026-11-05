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
                    <span class="rounded-md {{ $assetType === 'futures' ? 'bg-amber-100 dark:bg-amber-950/70 text-amber-800 dark:text-amber-300 border border-amber-300/70 dark:border-amber-800' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300' }} px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wider" data-testid="instrument-asset-type">
                        {{ $assetType === 'futures' ? __('Futures') : __('Stock') }}
                    </span>
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
                    @if ($assetType === 'futures' && !empty($instrumentMeta['trade_month']))
                        <span class="rounded-md bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-200 dark:border-emerald-800 px-2.5 py-0.5 text-xs font-mono font-medium text-emerald-700 dark:text-emerald-300" data-testid="futures-trade-month">
                            {{ __('Front Contract') }}: {{ $instrumentMeta['trade_month'] }}
                        </span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <div class="text-base font-medium text-zinc-600 dark:text-zinc-400" data-testid="company-name">
                        {{ $this->companyName ?? __('Company name unavailable') }}
                    </div>
                    @if ($assetType === 'futures')
                        <span class="text-xs font-mono text-zinc-500 dark:text-zinc-400" data-testid="instrument-price-unit">
                            {{ __('Unit') }}: <strong class="text-zinc-700 dark:text-zinc-200">{{ $this->priceUnit }}</strong>
                            @if (!empty($instrumentMeta['contract_size']))
                                • {{ __('Contract') }}: {{ $instrumentMeta['contract_size'] }}
                            @endif
                        </span>
                    @endif
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

        @if ($assetType === 'futures')
            <div class="mt-4 pt-3 border-t border-zinc-200/80 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-3 text-xs text-zinc-600 dark:text-zinc-400" data-testid="futures-contract-info">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span class="inline-flex items-center gap-1.5">
                        <flux:icon icon="clock" variant="micro" class="size-3.5 text-amber-600 dark:text-amber-400" />
                        <span>{{ $instrumentMeta['market_hours'] ?? '' }}</span>
                    </span>
                    <span class="hidden md:inline text-zinc-300 dark:text-zinc-700">|</span>
                    <span class="text-zinc-500 dark:text-zinc-400" data-testid="futures-rollover-note">
                        {{ $instrumentMeta['rollover_note'] ?? '' }}
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-1.5" data-testid="futures-quick-links">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 mr-1">{{ __('Commodities') }}:</span>
                    @foreach (['gcusd' => 'GCUSD', 'siusd' => 'SIUSD', 'clusd' => 'CLUSD', 'ngusd' => 'NGUSD', 'hgusd' => 'HGUSD'] as $slug => $label)
                        <a href="/futures/{{ $slug }}"
                           class="px-2 py-0.5 rounded font-mono text-xs transition-colors {{ strtolower($symbol) === $slug ? 'bg-amber-500/20 text-amber-800 dark:text-amber-300 font-bold border border-amber-400/50' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-white' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Main Chart & Interactive News Timeline Section --}}
    <div wire:ignore class="space-y-6"
         x-data="{
             symbol: @js($symbol),
             canonicalSymbol: @js($canonicalSymbol),
             assetType: @js($assetType),
             priceUnit: @js($this->priceUnit),
             apiBasePath: @js($assetType === 'futures' ? '/futures/' . $canonicalSymbol : '/stocks/' . $symbol),
             userId: @js((string)(auth()->id() ?? 'guest')),
             userTimezone: @js(auth()->user()?->timezone() ?? 'America/New_York'),
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
                 { id: '4h', label: '4h', api: '4hour', vela: '4h' },
                 { id: '1D', label: '1D', api: '1day', vela: '1D' },
                 { id: '1W', label: '1W', api: '1week', vela: '1W' },
                 { id: '1M', label: '1M', api: '1month', vela: '1M' },
             ],
             ranges: ['1D', '7D', '1M', '3M', '6M', 'YTD', '1Y', '5Y', 'All'],

             // Interactive News Timeline & Chart Sync state
             newsEnabled: @js($newsEnabled ?? true),
             newsStatus: @js($newsStatus ?? 'available'),
             newsStatusMessage: @js($newsStatusMessage ?? null),
             newsItems: @js($newsItems),
             newsMarkers: @js($newsMarkers),
             newsCategories: @js($newsCategories),
             relatedSymbols: @js($instrumentMeta['related_symbols'] ?? []),
             showNewsMarkers: true,
             syncNewsWithChart: true,
             selectedCategory: 'all',
             newsSearch: '',
             visibleFromTs: null,
             visibleToTs: null,
             isNewsLoading: false,
             newsRequestId: 0,
             newsAbortController: null,
             newsPollCount: 0,
             newsPollTimer: null,
             selectedNewsId: null,
             selectedNewsItem: null,
             impactData: null,
             isImpactLoading: false,
             impactError: null,
             impactRequestId: 0,
             impactAbortController: null,
             activeComparisonSymbols: @js(array_values(array_map(fn($r) => $r['symbol'], $instrumentMeta['related_symbols'] ?? []))),
             clickedCandle: null,
             candleInvestigation: null,
             includeLookAhead: true,
             viewportDebounceTimer: null,
             lastFetchedNewsFrom: @js($newsFromTimestamp ?? null),
             lastFetchedNewsTo: @js($newsToTimestamp ?? null),

             get filteredNewsItems() {
                 let list = Array.isArray(this.newsItems) ? this.newsItems : [];

                 if (this.selectedCategory && this.selectedCategory !== 'all') {
                     const cat = this.selectedCategory.toLowerCase();
                     list = list.filter(item => {
                         const keys = Array.isArray(item.category_keys) ? item.category_keys : [];
                         return keys.some(k => String(k).toLowerCase() === cat) ||
                                String(item.primary_category_key || '').toLowerCase() === cat;
                     });
                 }

                 if (this.newsSearch && this.newsSearch.trim() !== '') {
                     const q = this.newsSearch.trim().toLowerCase();
                     list = list.filter(item =>
                         String(item.title || '').toLowerCase().includes(q) ||
                         String(item.summary || '').toLowerCase().includes(q) ||
                         String(item.source || '').toLowerCase().includes(q)
                     );
                 }

                 if (this.candleInvestigation && !this.includeLookAhead) {
                     list = list.filter(item => item.window_phase !== 'look_ahead');
                 }

                 if (this.syncNewsWithChart && !this.candleInvestigation && this.visibleFromTs && this.visibleToTs) {
                     const padSec = this.selectedInterval === '1D' ? 86400 : 3600;
                     const minTs = this.visibleFromTs - padSec;
                     const maxTs = this.visibleToTs + padSec;
                     const inRange = list.filter(item => item.published_timestamp >= minTs && item.published_timestamp <= maxTs);
                     return inRange;
                 }

                 return list;
             },

             get visibleRangeLabel() {
                 if (this.candleInvestigation) {
                     return 'Candle Investigation Window (' + this.formatTimestampUtc(this.candleInvestigation.candle_timestamp) + ')';
                 }
                 if (!this.syncNewsWithChart || !this.visibleFromTs || !this.visibleToTs) {
                     return 'All Loaded News';
                 }
                 return this.formatDateShort(this.visibleFromTs) + ' – ' + this.formatDateShort(this.visibleToTs);
             },

             formatDateShort(tsSec) {
                 if (!tsSec) return '';
                 try {
                     const d = new Date(tsSec * 1000);
                     return d.toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
                 } catch (e) {
                     return '';
                 }
             },

             formatTimestampUtc(tsSec) {
                 if (!tsSec) return '';
                 try {
                     const d = new Date((tsSec > 1e11 ? tsSec : tsSec * 1000));
                     return d.toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
                 } catch (e) {
                     return '';
                 }
             },

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
                     if (saved && typeof saved.showNewsMarkers === 'boolean') {
                         this.showNewsMarkers = saved.showNewsMarkers;
                     }
                     if (saved && typeof saved.syncNewsWithChart === 'boolean') {
                         this.syncNewsWithChart = saved.syncNewsWithChart;
                     }
                 } catch (e) {
                     // ignore storage errors
                 }

                 if (this.newsItems.length > 0 && !this.selectedNewsItem) {
                     this.selectedNewsItem = this.newsItems[0];
                     this.selectedNewsId = this.newsItems[0].id;
                 }

                 if (this.newsEnabled && this.newsStatus === 'refreshing') {
                     this.scheduleNewsPoll();
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
                         userId: this.userId,
                         timezone: this.userTimezone,
                         activeRange: this.selectedRange,
                         newsMarkers: this.newsMarkers,
                         showNewsMarkers: this.showNewsMarkers,
                         selectedNewsId: this.selectedNewsId,
                         onTimezoneChange: (newTz) => {
                             if (newTz && newTz !== this.userTimezone) {
                                 this.userTimezone = newTz;
                                 if (this.$wire && typeof this.$wire.setTimezone === 'function') {
                                     this.$wire.setTimezone(newTz);
                                 }
                                 this.fetchCandles({ refresh: true });
                             }
                         },
                         onRangeChange: (rangeId, suggestedInterval) => {
                             if (suggestedInterval && this.selectedInterval !== suggestedInterval) {
                                 this.selectedInterval = suggestedInterval;
                             }
                             this.changeRange(rangeId);
                         },
                         onMarkerClick: (payload) => {
                             this.handleChartMarkerClick(payload);
                         },
                         onViewportChange: (range) => {
                             this.handleViewportChange(range);
                         },
                         onCandleClick: (candle) => {
                             this.handleCandleClick(candle);
                         },
                     });
                     if (this.candles.length > 0 && !this.workspace) {
                         this.errorMessage = '{{ __('Unable to initialize financial chart display.') }}';
                     } else if (this.candles.length > 0) {
                         const vr = this.workspace?.getVisibleTimeRange?.();
                         if (vr && vr.from && vr.to) {
                             this.visibleFromTs = Math.floor(vr.from / 1000);
                             this.visibleToTs = Math.floor(vr.to / 1000);
                         } else {
                             const firstT = this.candles[0]?.time;
                             const lastT = this.candles[this.candles.length - 1]?.time;
                             this.visibleFromTs = firstT > 1e11 ? Math.floor(firstT / 1000) : firstT;
                             this.visibleToTs = lastT > 1e11 ? Math.floor(lastT / 1000) : lastT;
                         }
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
                         showNewsMarkers: this.showNewsMarkers,
                         syncNewsWithChart: this.syncNewsWithChart,
                     }));
                 } catch (e) {
                     // ignore
                 }
             },
             toggleNewsMarkers() {
                 this.showNewsMarkers = !this.showNewsMarkers;
                 this.savePreferences();
                 this.workspace?.syncNewsMarkers?.(this.newsMarkers, this.showNewsMarkers, this.selectedNewsId);
             },
             toggleSyncNewsWithChart() {
                 this.syncNewsWithChart = !this.syncNewsWithChart;
                 this.savePreferences();
             },
             handleViewportChange(range) {
                 if (!range || !range.from || !range.to) return;
                 this.visibleFromTs = Math.floor(range.from / 1000);
                 this.visibleToTs = Math.floor(range.to / 1000);

                 if (!this.newsEnabled || !this.syncNewsWithChart || this.candleInvestigation) return;

                 if (this.viewportDebounceTimer) {
                     clearTimeout(this.viewportDebounceTimer);
                 }
                 this.viewportDebounceTimer = setTimeout(() => {
                     const fromIso = new Date(this.visibleFromTs * 1000).toISOString();
                     const toIso = new Date(this.visibleToTs * 1000).toISOString();
                     // Fetch news when navigating backward OR forward outside previously fetched window
                     const needsEarlier = !this.lastFetchedNewsFrom || (this.visibleFromTs < this.lastFetchedNewsFrom - 3600);
                     const needsLater = !this.lastFetchedNewsTo || (this.visibleToTs > this.lastFetchedNewsTo + 3600);
                     if (needsEarlier || needsLater) {
                         this.fetchNews({ from: fromIso, to: toIso, merge: true });
                     }
                 }, 400);
             },
             handleChartMarkerClick(payload) {
                 if (!payload) return;
                 const targetId = payload.id || (Array.isArray(payload.ids) ? payload.ids[0] : null);
                 let found = null;
                 if (targetId) {
                     found = this.newsItems.find(item => String(item.id) === String(targetId));
                 }
                 if (!found && payload.time) {
                     const targetSec = Math.floor((payload.time > 1e11 ? payload.time : payload.time * 1000) / 1000);
                     let bestDiff = Infinity;
                     for (const item of this.newsItems) {
                         const d = Math.abs((item.marker_timestamp || item.published_timestamp) - targetSec);
                         if (d < bestDiff) {
                             bestDiff = d;
                             found = item;
                         }
                     }
                 }
                 if (found) {
                     this.selectNewsItem(found, false);
                     this.$nextTick(() => {
                         const el = document.querySelector(`[data-news-id='${found.id}']`);
                         el?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
                     });
                 }
             },
             handleCandleClick(candle) {
                 if (!candle) return;
                 this.clickedCandle = candle;
             },
             async investigateCandleNews(candle) {
                 const target = candle || this.clickedCandle;
                 if (!target || !target.timestamp) return;
                 await this.fetchNews({
                     around_timestamp: target.timestamp,
                     pre_window_minutes: 720,
                     look_ahead_minutes: 120,
                 });
                 this.fetchImpactByTimestamp(target.timestamp);
             },
             clearCandleInvestigation() {
                 this.candleInvestigation = null;
                 this.clickedCandle = null;
                 this.workspace?.clearHighlight?.();
                 this.fetchNews();
             },
             selectNewsItem(item, navigateChart = true) {
                 if (!item) return;
                 this.selectedNewsId = item.id;
                 this.selectedNewsItem = item;
                 this.workspace?.syncNewsMarkers?.(this.newsMarkers, this.showNewsMarkers, this.selectedNewsId);

                 if (navigateChart) {
                     this.navigateToNewsTimestamp(item.marker_timestamp || item.published_timestamp);
                 }

                 this.fetchNewsImpact(item);
             },
             async navigateToNewsTimestamp(tsSec) {
                 if (!tsSec) return;
                 const tsMs = tsSec > 1e11 ? tsSec : tsSec * 1000;

                 if (this.candles.length > 0) {
                     const firstMs = this.candles[0].time > 1e11 ? this.candles[0].time : this.candles[0].time * 1000;
                     const lastMs = this.candles[this.candles.length - 1].time > 1e11 ? this.candles[this.candles.length - 1].time : this.candles[this.candles.length - 1].time * 1000;

                     if (tsMs >= firstMs - 86400000 && tsMs <= lastMs + 86400000) {
                         this.workspace?.focusTimestamp?.(tsMs, { windowBars: 20 });
                         return;
                     }
                 }

                 // If event is outside currently loaded candles, load a window around that event date
                 const eventDate = new Date(tsMs);
                 const fromDate = new Date(tsMs - 14 * 86400000);
                 const toDate = new Date(Math.min(Date.now(), tsMs + 14 * 86400000));
                 await this.fetchCandles({
                     customFrom: fromDate.toISOString().slice(0, 10),
                     customTo: toDate.toISOString().slice(0, 10),
                     focusTimeMs: tsMs,
                 });
             },
             scheduleNewsPoll() {
                 if (!this.newsEnabled || this.newsPollCount >= 4) return;
                 if (this.newsPollTimer) {
                     clearTimeout(this.newsPollTimer);
                 }
                 this.newsPollTimer = setTimeout(async () => {
                     this.newsPollCount++;
                     await this.fetchNews({ isPoll: true });
                 }, 1500);
             },
             mergeNewsLists(existingList, incomingList) {
                 const map = new Map();
                 for (const item of (Array.isArray(existingList) ? existingList : [])) {
                     if (item && item.id) map.set(String(item.id), item);
                 }
                 for (const item of (Array.isArray(incomingList) ? incomingList : [])) {
                     if (item && item.id) map.set(String(item.id), item);
                 }
                 const merged = Array.from(map.values());
                 merged.sort((a, b) => (b.published_timestamp || 0) - (a.published_timestamp || 0));
                 return merged;
             },
             mergeMarkerLists(existingMarkers, incomingMarkers) {
                 const map = new Map();
                 for (const m of (Array.isArray(existingMarkers) ? existingMarkers : [])) {
                     if (m && m.id) map.set(String(m.id), m);
                 }
                 for (const m of (Array.isArray(incomingMarkers) ? incomingMarkers : [])) {
                     if (m && m.id) map.set(String(m.id), m);
                 }
                 const merged = Array.from(map.values());
                 merged.sort((a, b) => (a.timestamp || 0) - (b.timestamp || 0));
                 return merged;
             },
             async fetchNews(params = {}) {
                 if (!this.newsEnabled) return;
                 if (this.newsAbortController) {
                     try { this.newsAbortController.abort(); } catch (e) {}
                 }
                 this.newsAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
                 const currentNewsReqId = ++this.newsRequestId;

                 if (!params.isPoll) {
                     this.newsPollCount = 0;
                 }
                 this.isNewsLoading = true;

                 try {
                     const qs = new URLSearchParams();
                     if (params.from) qs.set('from', params.from);
                     if (params.to) qs.set('to', params.to);
                     if (params.refresh) qs.set('refresh', '1');
                     if (params.around_timestamp) {
                         qs.set('around_timestamp', params.around_timestamp);
                         qs.set('pre_window_minutes', params.pre_window_minutes || 720);
                         qs.set('look_ahead_minutes', params.look_ahead_minutes || 120);
                     }
                     qs.set('limit', params.limit || 50);

                     const resp = await fetch(`${this.apiBasePath}/news?${qs.toString()}`, {
                         headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                         signal: this.newsAbortController ? this.newsAbortController.signal : undefined,
                     });

                     if (currentNewsReqId !== this.newsRequestId) return;

                     if (!resp.ok) {
                         if (resp.status === 429) {
                             this.newsStatus = 'rate_limited';
                             this.newsStatusMessage = '{{ __('News provider rate limit exceeded. Please retry shortly.') }}';
                         } else if (resp.status === 401 || resp.status === 402 || resp.status === 403) {
                             this.newsStatus = 'auth_error';
                             this.newsStatusMessage = '{{ __('News provider authorization or subscription error.') }}';
                         } else {
                             this.newsStatus = 'service_unavailable';
                             this.newsStatusMessage = '{{ __('External news service is temporarily unavailable.') }}';
                         }
                         return;
                     }

                     const data = await resp.json();
                     if (currentNewsReqId !== this.newsRequestId) return;

                     this.newsStatus = data.status || (Array.isArray(data.items) && data.items.length > 0 ? 'available' : 'empty');
                     this.newsStatusMessage = data.status_message || null;

                     if (Array.isArray(data.items)) {
                         if (params.merge && !params.around_timestamp) {
                             this.newsItems = this.mergeNewsLists(this.newsItems, data.items);
                             this.newsMarkers = this.mergeMarkerLists(this.newsMarkers, Array.isArray(data.markers) ? data.markers : []);
                             if (data.from_timestamp) {
                                 this.lastFetchedNewsFrom = this.lastFetchedNewsFrom
                                     ? Math.min(this.lastFetchedNewsFrom, data.from_timestamp)
                                     : data.from_timestamp;
                             }
                             if (data.to_timestamp) {
                                 this.lastFetchedNewsTo = this.lastFetchedNewsTo
                                     ? Math.max(this.lastFetchedNewsTo, data.to_timestamp)
                                     : data.to_timestamp;
                             }
                         } else {
                             this.newsItems = data.items;
                             this.newsMarkers = Array.isArray(data.markers) ? data.markers : [];
                             this.candleInvestigation = data.investigation || null;
                             this.lastFetchedNewsFrom = data.from_timestamp || null;
                             this.lastFetchedNewsTo = data.to_timestamp || null;
                         }

                         if (this.newsItems.length > 0 && (!this.selectedNewsId || !this.newsItems.some(i => i.id === this.selectedNewsId))) {
                             this.selectedNewsItem = this.newsItems[0];
                             this.selectedNewsId = this.newsItems[0].id;
                         } else if (this.selectedNewsId) {
                             const updatedSelected = this.newsItems.find(i => i.id === this.selectedNewsId);
                             if (updatedSelected) {
                                 this.selectedNewsItem = updatedSelected;
                             }
                         }
                         this.workspace?.syncNewsMarkers?.(this.newsMarkers, this.showNewsMarkers, this.selectedNewsId);
                     }

                     if (this.newsStatus === 'refreshing') {
                         this.scheduleNewsPoll();
                     }
                 } catch (e) {
                     if (e?.name === 'AbortError') return;
                     if (currentNewsReqId !== this.newsRequestId) return;
                     console.warn('Error fetching news timeline:', e);
                     if (this.newsItems.length === 0) {
                         this.newsStatus = 'service_unavailable';
                         this.newsStatusMessage = '{{ __('External news service is temporarily unavailable. Please retry.') }}';
                     }
                 } finally {
                     if (currentNewsReqId === this.newsRequestId) {
                         this.isNewsLoading = false;
                     }
                 }
             },
             async fetchNewsImpact(item) {
                 if (!item) return;
                 const ts = item.marker_timestamp || item.published_timestamp;
                 if (!ts) return;
                 await this.fetchImpactByTimestamp(ts);
             },
             async fetchImpactByTimestamp(ts) {
                 if (this.impactAbortController) {
                     try { this.impactAbortController.abort(); } catch (e) {}
                 }
                 this.impactAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
                 const currentImpactReqId = ++this.impactRequestId;

                 this.isImpactLoading = true;
                 this.impactError = null;
                 try {
                     const compareParam = this.activeComparisonSymbols.join(',');
                     const resp = await fetch(`${this.apiBasePath}/news-impact?timestamp=${encodeURIComponent(ts)}&compare=${encodeURIComponent(compareParam)}`, {
                         headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                         signal: this.impactAbortController ? this.impactAbortController.signal : undefined,
                     });
                     if (currentImpactReqId !== this.impactRequestId) return;
                     if (!resp.ok) {
                         throw new Error(`HTTP ${resp.status}`);
                     }
                     const data = await resp.json();
                     if (currentImpactReqId !== this.impactRequestId) return;
                     this.impactData = data;
                 } catch (e) {
                     if (e?.name === 'AbortError') return;
                     if (currentImpactReqId !== this.impactRequestId) return;
                     this.impactError = '{{ __('Price impact data unavailable for this event.') }}';
                     this.impactData = null;
                 } finally {
                     if (currentImpactReqId === this.impactRequestId) {
                         this.isImpactLoading = false;
                     }
                 }
             },
             toggleComparisonSymbol(sym) {
                 const idx = this.activeComparisonSymbols.indexOf(sym);
                 if (idx >= 0) {
                     this.activeComparisonSymbols.splice(idx, 1);
                 } else {
                     this.activeComparisonSymbols.push(sym);
                 }
                 if (this.selectedNewsItem) {
                     this.fetchNewsImpact(this.selectedNewsItem);
                 }
             },
             changeInterval(intervalId) {
                 if (this.selectedInterval === intervalId && !this.errorMessage) return;
                 this.selectedInterval = intervalId;

                 // Sensible range adjustments for intraday intervals
                 if (['1m', '5m'].includes(intervalId)) {
                     if (['5Y', 'All', '1Y', 'YTD', '6M', '3M'].includes(this.selectedRange)) {
                         this.selectedRange = '1D';
                     }
                 } else if (['15m', '30m'].includes(intervalId)) {
                     if (['5Y', 'All', '1Y', 'YTD'].includes(this.selectedRange)) {
                         this.selectedRange = '7D';
                     }
                 } else if (['1h', '4h'].includes(intervalId)) {
                     if (['5Y', 'All'].includes(this.selectedRange)) {
                         this.selectedRange = '1M';
                     }
                 } else if (['1W', '1M'].includes(intervalId)) {
                     if (['1D', '7D', '1M'].includes(this.selectedRange)) {
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
                 if (['1M', '3M', '6M', 'YTD', '1Y', '5Y', 'All'].includes(rangeId) && ['1m', '5m', '15m', '30m'].includes(this.selectedInterval)) {
                     this.selectedInterval = '1D';
                 } else if (rangeId === '1D' && !['1m', '5m', '15m'].includes(this.selectedInterval)) {
                     this.selectedInterval = '5m';
                 } else if (rangeId === '7D' && !['5m', '15m', '30m', '1h', '4h'].includes(this.selectedInterval)) {
                     this.selectedInterval = '15m';
                 }

                 this.savePreferences();
                 this.fetchCandles();
             },
             async fetchCandles(options = {}) {
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
                     case '1D':
                         fromDate.setDate(fromDate.getDate() - 1);
                         break;
                     case '7D':
                         fromDate.setDate(fromDate.getDate() - 7);
                         break;
                     case '1M':
                         fromDate.setDate(fromDate.getDate() - 30);
                         break;
                     case '3M':
                         fromDate.setDate(fromDate.getDate() - 90);
                         break;
                     case '6M':
                         fromDate.setDate(fromDate.getDate() - 180);
                         break;
                     case 'YTD':
                         fromDate.setMonth(0, 1);
                         break;
                     case '1Y':
                         fromDate.setDate(fromDate.getDate() - 365);
                         break;
                     case '5Y':
                         fromDate.setFullYear(fromDate.getFullYear() - 5);
                         break;
                     case 'All':
                     case 'ALL':
                         fromDate.setFullYear(fromDate.getFullYear() - 20);
                         break;
                     default:
                         fromDate.setDate(fromDate.getDate() - 365);
                 }

                 const fromStr = options.customFrom || fromDate.toISOString().slice(0, 10);
                 const toStr = options.customTo || toDate.toISOString().slice(0, 10);
                 const activeItem = this.intervals.find(i => i.id === this.selectedInterval) || { api: '1day' };

                 let url = `/stocks/${encodeURIComponent(this.symbol)}/candles?interval=${activeItem.api}&from=${fromStr}&to=${toStr}&range=${this.selectedRange}`;
                 if (options && options.refresh) {
                     url += '&refresh=1';
                 }

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

                     if (options.focusTimeMs) {
                         this.$nextTick(() => {
                             this.workspace?.focusTimestamp?.(options.focusTimeMs, { windowBars: 20 });
                         });
                     }
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
                     if (activeAbortController) { try { activeAbortController.abort(); } catch (e) {} }
                     if (newsAbortController) { try { newsAbortController.abort(); } catch (e) {} }
                     if (impactAbortController) { try { impactAbortController.abort(); } catch (e) {} }
                     if (viewportDebounceTimer) { clearTimeout(viewportDebounceTimer); }
                     if (newsPollTimer) { clearTimeout(newsPollTimer); }
                     if (workspace && typeof workspace.destroy === 'function') {
                         try { workspace.destroy(); } catch (e) {}
                         workspace = null;
                     }
                     if (typeof window.cleanupVelaStyles === 'function') {
                         try { window.cleanupVelaStyles(); } catch (e) {}
                     }
                 });
             }
         ">

        {{-- Chart Card --}}
        <div class="da-card p-0 overflow-hidden bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-xs"
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
                            ['id' => '4h', 'label' => '4h'],
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

            {{-- Right: Range buttons, News Markers toggle, Loading indicator & Fullscreen --}}
            <div class="flex items-center gap-2 sm:gap-3 ml-auto">
                {{-- Range Presets --}}
                <div class="flex items-center gap-1" data-testid="range-controls">
                    <span class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400 dark:text-zinc-500 mr-0.5 hidden lg:inline">
                        {{ __('Range') }}:
                    </span>
                    <div class="inline-flex rounded-md p-0.5 bg-zinc-200/50 dark:bg-zinc-800/60">
                        @foreach (['1D', '7D', '1M', '3M', '6M', 'YTD', '1Y', '5Y', 'All'] as $rng)
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

                {{-- News Markers Toggle --}}
                @if ($newsEnabled ?? true)
                    <button type="button"
                            x-show="newsEnabled"
                            x-on:click="toggleNewsMarkers()"
                            :class="showNewsMarkers ? 'bg-amber-50 dark:bg-amber-950/70 border-amber-300 dark:border-amber-800 text-amber-800 dark:text-amber-300' : 'bg-white dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-500 dark:text-zinc-400'"
                            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors"
                            title="{{ __('Toggle News Event Markers on Chart') }}"
                            data-testid="toggle-news-markers">
                        <span class="size-2 rounded-full" :class="showNewsMarkers ? 'bg-amber-500' : 'bg-zinc-400'"></span>
                        <span x-text="showNewsMarkers ? '{{ __('News Markers: On') }}' : '{{ __('News Markers: Off') }}'"></span>
                    </button>
                @endif

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

        {{-- Clicked Candle Investigation Contextual Bar (Scenario C) --}}
        <div x-show="clickedCandle || candleInvestigation"
             x-cloak
             class="flex flex-wrap items-center justify-between gap-3 px-4 py-2 border-b border-amber-200 dark:border-amber-900/60 bg-amber-50/80 dark:bg-amber-950/40 text-xs"
             data-testid="investigate-candle-bar">
            <div class="flex flex-wrap items-center gap-3">
                <span class="font-semibold text-amber-900 dark:text-amber-200 flex items-center gap-1.5">
                    <flux:icon icon="magnifying-glass-circle" variant="mini" class="size-4 text-amber-600 dark:text-amber-400" />
                    <span>{{ __('Selected Candle') }}:</span>
                </span>
                <template x-if="clickedCandle">
                    <div class="flex items-center gap-2 font-mono text-zinc-700 dark:text-zinc-300">
                        <span x-text="formatTimestampUtc(clickedCandle.timestamp)"></span>
                        <span>•</span>
                        <span x-text="'Close: $' + Number(clickedCandle.close).toFixed(2)"></span>
                        <span :class="clickedCandle.changePct >= 0 ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-rose-600 dark:text-rose-400 font-bold'"
                              x-text="'(' + (clickedCandle.changePct >= 0 ? '+' : '') + clickedCandle.changePct + '%)'"></span>
                    </div>
                </template>
            </div>
            <div class="flex items-center gap-2">
                <button type="button"
                        x-show="clickedCandle"
                        x-on:click="investigateCandleNews(clickedCandle)"
                        class="inline-flex items-center gap-1.5 rounded-md bg-amber-600 hover:bg-amber-700 text-white px-2.5 py-1 text-xs font-semibold shadow-2xs transition-colors cursor-pointer"
                        data-testid="show-related-news-btn">
                    <span>{{ __('Show related news') }}</span>
                </button>
                <button type="button"
                        x-on:click="clearCandleInvestigation()"
                        class="text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 px-2 py-1"
                        data-testid="clear-candle-investigation-btn">
                    {{ __('Clear') }}
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

        {{-- Interactive News Timeline & Event Price-Impact Analysis --}}
        @if ($newsEnabled ?? true)
        <div x-show="newsEnabled" class="da-card bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-xs" data-testid="news-timeline-panel" :data-news-status="newsStatus">
            {{-- Header & Controls --}}
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-amber-100 dark:bg-amber-950/80 text-amber-600 dark:text-amber-400">
                        <flux:icon icon="book-open-text" variant="mini" class="size-5" />
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                            <span>{{ __('Interactive News Timeline') }}</span>
                            <span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-2.5 py-0.5 text-xs font-mono font-semibold text-zinc-600 dark:text-zinc-300"
                                  x-text="filteredNewsItems.length"
                                  data-testid="news-timeline-count"></span>
                            <span x-show="isNewsLoading || newsStatus === 'refreshing'"
                                  x-cloak
                                  class="inline-flex items-center gap-1 rounded-full bg-sky-100 dark:bg-sky-950/80 px-2 py-0.5 text-[11px] font-medium text-sky-700 dark:text-sky-300"
                                  data-testid="news-refreshing-badge">
                                <flux:icon icon="arrow-path" variant="mini" class="size-3 animate-spin" />
                                <span>{{ __('Refreshing...') }}</span>
                            </span>
                        </h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Synchronized with the Vela chart. Click any headline to navigate the chart and inspect post-event price behavior, or click a chart marker/candle to filter stories.') }}
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    {{-- Active Visible Window Badge --}}
                    <span class="rounded-md bg-zinc-100 dark:bg-zinc-800 px-2.5 py-1 text-xs font-mono text-zinc-600 dark:text-zinc-300"
                          x-text="visibleRangeLabel"
                          data-testid="news-visible-range-label"></span>

                    {{-- Sync Timeline with Chart Visible Range Toggle --}}
                    <button type="button"
                            x-on:click="toggleSyncNewsWithChart()"
                            :class="syncNewsWithChart ? 'bg-sky-50 dark:bg-sky-950/70 border-sky-300 dark:border-sky-800 text-sky-800 dark:text-sky-300' : 'bg-white dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-600 dark:text-zinc-400'"
                            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors cursor-pointer"
                            data-testid="toggle-sync-chart-range">
                        <span class="size-2 rounded-full" :class="syncNewsWithChart ? 'bg-sky-500' : 'bg-zinc-400'"></span>
                        <span x-text="syncNewsWithChart ? '{{ __('Follow Chart Range: On') }}' : '{{ __('Follow Chart Range: Off') }}'"></span>
                    </button>

                    {{-- Manual Refresh News Button --}}
                    <button type="button"
                            x-on:click="fetchNews({ refresh: true })"
                            :disabled="isNewsLoading"
                            class="inline-flex items-center gap-1 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:bg-zinc-50 dark:hover:bg-zinc-700 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:text-zinc-300 transition-colors cursor-pointer"
                            title="{{ __('Refresh News Feed') }}"
                            data-testid="refresh-news-btn">
                        <flux:icon icon="arrow-path" variant="mini" class="size-3.5" ::class="isNewsLoading ? 'animate-spin text-sky-500' : ''" />
                        <span>{{ __('Refresh') }}</span>
                    </button>

                    {{-- Look-ahead toggle during candle investigation --}}
                    <button type="button"
                            x-show="candleInvestigation"
                            x-cloak
                            x-on:click="includeLookAhead = !includeLookAhead"
                            :class="includeLookAhead ? 'bg-violet-50 dark:bg-violet-950/70 border-violet-300 dark:border-violet-800 text-violet-800 dark:text-violet-300' : 'bg-white dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700 text-zinc-500'"
                            class="inline-flex items-center gap-1 rounded-md border px-2.5 py-1 text-xs font-medium"
                            data-testid="toggle-look-ahead-window">
                        <span x-text="includeLookAhead ? '{{ __('Look-Ahead (+2h): Shown') }}' : '{{ __('Look-Ahead (+2h): Hidden') }}'"></span>
                    </button>
                </div>
            </div>

            {{-- Provider Status / Error Banner (Auth, Rate Limit, Service Unavailable) --}}
            <div x-show="['auth_error', 'rate_limited', 'service_unavailable'].includes(newsStatus)"
                 x-cloak
                 class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-xs"
                 :class="newsStatus === 'rate_limited'
                     ? 'border-amber-300 dark:border-amber-800 bg-amber-50/90 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200'
                     : 'border-rose-300 dark:border-rose-800 bg-rose-50/90 dark:bg-rose-950/40 text-rose-900 dark:text-rose-200'"
                 data-testid="news-error-state"
                 :data-status="newsStatus">
                <div class="flex items-center gap-2.5">
                    <flux:icon icon="exclamation-triangle" variant="mini" class="size-4 shrink-0" />
                    <div>
                        <span class="font-bold uppercase tracking-wide mr-1.5"
                              x-text="newsStatus === 'auth_error' ? '{{ __('Provider Authorization / Subscription Error:') }}' : (newsStatus === 'rate_limited' ? '{{ __('Rate Limit Exceeded:') }}' : '{{ __('News Service Unavailable:') }}')"></span>
                        <span x-text="newsStatusMessage || '{{ __('Unable to retrieve news from external provider.') }}'"></span>
                    </div>
                </div>
                <button type="button"
                        x-on:click="fetchNews({ refresh: true })"
                        class="da-btn da-btn-secondary text-xs px-3 py-1 shrink-0"
                        data-testid="news-retry-btn">
                    {{ __('Retry') }}
                </button>
            </div>

            {{-- Category Filter Chips & Search --}}
            <div class="mt-4 flex flex-col md:flex-row md:items-center justify-between gap-3" data-testid="news-category-filters">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button"
                            x-on:click="selectedCategory = 'all'"
                            :class="selectedCategory === 'all' ? 'bg-zinc-900 dark:bg-white text-white dark:text-zinc-900 font-semibold' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-zinc-700'"
                            class="rounded-full px-3 py-1 text-xs transition-colors cursor-pointer"
                            data-testid="news-category-all">
                        {{ __('All Topics') }}
                    </button>
                    <template x-for="cat in newsCategories" :key="cat.key">
                        <button type="button"
                                x-on:click="selectedCategory = cat.key"
                                :class="selectedCategory === cat.key ? 'bg-sky-600 text-white font-semibold' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 hover:bg-zinc-200 dark:hover:bg-zinc-700'"
                                class="rounded-full px-3 py-1 text-xs transition-colors cursor-pointer"
                                :data-testid="'news-category-' + cat.key"
                                x-text="cat.label">
                        </button>
                    </template>
                </div>

                <div class="w-full md:w-64">
                    <input type="search"
                           x-model="newsSearch"
                           placeholder="{{ __('Filter headlines or sources...') }}"
                           class="da-input text-xs py-1.5"
                           data-testid="news-search-input" />
                </div>
            </div>

            {{-- Split Layout: Left Timeline List / Right Selected Event & Price-Impact Analysis --}}
            <div class="mt-5 grid grid-cols-1 lg:grid-cols-12 gap-6">
                {{-- Left: Timeline Feed (7 cols) --}}
                <div class="lg:col-span-7 space-y-2.5 max-h-[540px] overflow-y-auto pr-1" data-testid="news-timeline-list">
                    <template x-for="item in filteredNewsItems" :key="item.id">
                        <div x-on:click="selectNewsItem(item, true)"
                             :data-news-id="item.id"
                             :data-testid="'news-item-' + item.id"
                             :class="selectedNewsId === item.id ? 'border-amber-500 dark:border-amber-500 bg-amber-50/50 dark:bg-amber-950/30 ring-1 ring-amber-400/60' : 'border-zinc-200 dark:border-zinc-800 bg-zinc-50/40 dark:bg-zinc-900/40 hover:bg-zinc-100/70 dark:hover:bg-zinc-800/60'"
                             class="p-3.5 rounded-xl border transition-all cursor-pointer space-y-2">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-[11px]">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-200" x-text="item.source"></span>
                                    <span class="text-zinc-400">•</span>
                                    <span class="font-mono text-zinc-500 dark:text-zinc-400"
                                          :title="'First verifiable publication timestamp'"
                                          x-text="'Published: ' + item.published_display"></span>
                                    <template x-if="item.has_distinct_event_time && item.event_display">
                                        <span class="rounded bg-violet-100 dark:bg-violet-950/80 text-violet-700 dark:text-violet-300 px-1.5 py-0.5 font-mono"
                                              x-text="'Event: ' + item.event_display"></span>
                                    </template>
                                </div>

                                <div class="flex items-center gap-1.5">
                                    <template x-if="item.window_phase === 'look_ahead'">
                                        <span class="rounded bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300 px-1.5 py-0.5 font-mono text-[10px] font-semibold"
                                              data-testid="news-look-ahead-badge"
                                              x-text="'Look-Ahead (+' + item.offset_minutes + 'm)'"></span>
                                    </template>
                                    <span class="rounded bg-sky-100 dark:bg-sky-950 text-sky-700 dark:text-sky-300 px-2 py-0.5 font-medium"
                                          x-text="item.primary_category_label"></span>
                                </div>
                            </div>

                            <h3 class="text-sm font-bold text-zinc-900 dark:text-white leading-snug" x-text="item.title"></h3>

                            <p x-show="item.summary" class="text-xs text-zinc-600 dark:text-zinc-400 line-clamp-2" x-text="item.summary"></p>

                            <template x-if="item.ai_enriched && item.ai_rationale">
                                <div class="rounded-lg bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200/70 dark:border-indigo-900/60 px-2.5 py-1.5 text-[11px] text-indigo-900 dark:text-indigo-200 flex items-start gap-1.5"
                                     data-testid="news-ai-rationale">
                                    <span class="font-mono text-[10px] font-bold uppercase tracking-wider rounded bg-indigo-200/80 dark:bg-indigo-900 px-1.5 py-0.2 shrink-0">
                                        {{ __('AI Note (Unverified)') }}
                                    </span>
                                    <span x-text="item.ai_rationale"></span>
                                </div>
                            </template>

                            <div class="flex items-center justify-between pt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                                <span class="font-mono text-[10px]" x-text="'Relevance: ' + item.relevance_score + '/100'"></span>
                                <div class="flex items-center gap-3">
                                    <span class="text-sky-600 dark:text-sky-400 font-medium hover:underline">
                                        {{ __('Focus on Chart & Analyze Impact →') }}
                                    </span>
                                    <template x-if="item.url">
                                        <a :href="item.url"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           x-on:click.stop
                                           class="inline-flex items-center gap-1 text-zinc-600 dark:text-zinc-300 hover:text-sky-600 dark:hover:text-sky-400 font-medium underline"
                                           data-testid="news-original-link">
                                            <span>{{ __('Original Article') }}</span>
                                        </a>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div x-show="filteredNewsItems.length === 0 && !isNewsLoading && newsStatus !== 'refreshing'"
                         x-cloak
                         class="py-10 text-center text-zinc-500 dark:text-zinc-400 space-y-2"
                         data-testid="news-empty-state">
                        <template x-if="newsItems.length > 0">
                            <div class="space-y-2">
                                <p class="text-sm font-semibold">{{ __('No matching news events in the current filter window') }}</p>
                                <p class="text-xs text-zinc-400">
                                    <span x-text="newsItems.length + ' relevant articles are loaded for ' + symbol + ', but none match the current chart range (' + visibleRangeLabel + ') or active topic filter.'"></span>
                                </p>
                                <div class="flex items-center justify-center gap-2 pt-1">
                                    <button type="button"
                                            x-show="syncNewsWithChart"
                                            x-on:click="toggleSyncNewsWithChart()"
                                            class="da-btn da-btn-secondary text-xs px-3 py-1">
                                        {{ __('Show All Loaded News') }}
                                    </button>
                                    <button type="button"
                                            x-show="selectedCategory !== 'all' || newsSearch !== ''"
                                            x-on:click="selectedCategory = 'all'; newsSearch = ''"
                                            class="da-btn da-btn-secondary text-xs px-3 py-1">
                                        {{ __('Clear Topic / Search Filters') }}
                                    </button>
                                </div>
                            </div>
                        </template>
                        <template x-if="newsItems.length === 0 && !['auth_error', 'rate_limited', 'service_unavailable'].includes(newsStatus)">
                            <div class="space-y-2">
                                <p class="text-sm font-semibold">{{ __('No relevant news articles found') }}</p>
                                <p class="text-xs text-zinc-400" x-text="newsStatusMessage || '{{ __('No relevant articles were returned by the configured news sources for this instrument and date window.') }}'"></p>
                                <button type="button"
                                        x-on:click="fetchNews({ refresh: true })"
                                        class="mt-2 da-btn da-btn-secondary text-xs px-3 py-1">
                                    {{ __('Refresh News') }}
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Right: Selected Event Detail & Historical Price-Impact Summary (5 cols) --}}
                <div class="lg:col-span-5" data-testid="news-event-detail">
                    <template x-if="selectedNewsItem">
                        <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/30 space-y-4">
                            <div class="space-y-1.5 border-b border-zinc-200 dark:border-zinc-800 pb-3">
                                <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-200" x-text="selectedNewsItem.source"></span>
                                    <span class="font-mono" x-text="selectedNewsItem.published_display"></span>
                                </div>
                                <template x-if="selectedNewsItem.has_distinct_event_time && selectedNewsItem.event_display">
                                    <div class="text-xs font-mono text-violet-700 dark:text-violet-300" data-testid="selected-news-event-time">
                                        {{ __('Original Event Time') }}: <span x-text="selectedNewsItem.event_display"></span>
                                        ({{ __('Published') }}: <span x-text="selectedNewsItem.published_display"></span>)
                                    </div>
                                </template>
                                <h3 class="text-base font-bold text-zinc-950 dark:text-white" data-testid="selected-news-headline" x-text="selectedNewsItem.title"></h3>
                                <p class="text-xs text-zinc-600 dark:text-zinc-300 leading-relaxed" x-text="selectedNewsItem.summary"></p>

                                <div class="pt-2 flex flex-wrap items-center justify-between gap-2">
                                    <button type="button"
                                            x-on:click="navigateToNewsTimestamp(selectedNewsItem.marker_timestamp || selectedNewsItem.published_timestamp)"
                                            class="da-btn da-btn-secondary text-xs px-2.5 py-1"
                                            data-testid="navigate-chart-to-event-btn">
                                        {{ __('Center Chart on Event') }}
                                    </button>
                                    <template x-if="selectedNewsItem.url">
                                        <a :href="selectedNewsItem.url"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="text-xs font-semibold text-sky-600 dark:text-sky-400 hover:underline"
                                           data-testid="selected-news-article-link">
                                            {{ __('Read Original Article ↗') }}
                                        </a>
                                    </template>
                                </div>
                            </div>

                            {{-- Price-Impact Analysis Section --}}
                            <div class="space-y-3" data-testid="news-impact-panel">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-200">
                                            {{ __('Event Price-Impact Analysis') }}
                                        </h4>
                                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                            {{ __('Observed price changes over 5m, 15m, 30m, 60m, and 4h following publication.') }}
                                        </p>
                                    </div>
                                    <button type="button"
                                            x-on:click="fetchNewsImpact(selectedNewsItem)"
                                            class="text-xs font-medium text-sky-600 dark:text-sky-400 hover:underline cursor-pointer"
                                            data-testid="load-price-impact-btn">
                                        <span x-text="impactData ? '{{ __('Refresh Impact') }}' : '{{ __('Calculate Impact') }}'"></span>
                                    </button>
                                </div>

                                {{-- Related Market Comparison Toggles --}}
                                <template x-if="relatedSymbols.length > 0">
                                    <div class="flex flex-wrap items-center gap-1.5 text-xs" data-testid="related-market-toggles">
                                        <span class="text-[11px] text-zinc-500">{{ __('Compare vs') }}:</span>
                                        <template x-for="rel in relatedSymbols" :key="rel.symbol">
                                            <button type="button"
                                                    x-on:click="toggleComparisonSymbol(rel.symbol)"
                                                    :class="activeComparisonSymbols.includes(rel.symbol) ? 'bg-sky-100 dark:bg-sky-950 border-sky-400 text-sky-800 dark:text-sky-200 font-semibold' : 'bg-white dark:bg-zinc-900 border-zinc-200 dark:border-zinc-700 text-zinc-500'"
                                                    class="rounded border px-2 py-0.5 font-mono text-[11px] transition-colors cursor-pointer"
                                                    :data-testid="'compare-toggle-' + rel.symbol"
                                                    x-text="rel.symbol + ' (' + rel.name + ')'">
                                            </button>
                                        </template>
                                    </div>
                                </template>

                                <div x-show="isImpactLoading" class="py-4 text-center text-xs text-zinc-500 animate-pulse">
                                    {{ __('Computing historical intraday reaction across horizons...') }}
                                </div>

                                <template x-if="impactData && !isImpactLoading">
                                    <div class="space-y-3" data-testid="impact-results-table">
                                        {{-- Primary Instrument Horizons --}}
                                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-3 space-y-2">
                                            <div class="flex items-center justify-between text-xs">
                                                <span class="font-bold font-mono text-zinc-900 dark:text-white" x-text="impactData.primary.symbol + ' — ' + impactData.primary.name"></span>
                                                <span class="font-mono text-[11px] text-zinc-500"
                                                      x-text="impactData.primary.baseline_available ? ('Baseline: $' + Number(impactData.primary.baseline_price).toFixed(2) + ' (' + impactData.primary.baseline_display + ')') : 'Intraday baseline unavailable'"></span>
                                            </div>
                                            <div class="grid grid-cols-5 gap-1.5 text-center">
                                                <template x-for="h in impactData.primary.horizons" :key="h.key">
                                                    <div class="p-1.5 rounded bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200/60 dark:border-zinc-800"
                                                         :data-testid="'impact-horizon-' + h.key">
                                                        <div class="text-[10px] font-semibold text-zinc-500" x-text="h.key"></div>
                                                        <template x-if="h.available">
                                                            <div>
                                                                <div class="font-mono text-xs font-bold"
                                                                     :class="h.change_percent > 0 ? 'text-emerald-600 dark:text-emerald-400' : (h.change_percent < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-600')"
                                                                     x-text="(h.change_percent > 0 ? '+' : '') + Number(h.change_percent).toFixed(2) + '%'"></div>
                                                                <div class="font-mono text-[10px] text-zinc-400"
                                                                     x-text="(h.change > 0 ? '+$' : (h.change < 0 ? '-$' : '$')) + Math.abs(Number(h.change)).toFixed(2)"></div>
                                                            </div>
                                                        </template>
                                                        <template x-if="!h.available">
                                                            <div class="text-[10px] text-zinc-400 font-mono py-1" title="Intraday bars unavailable for this horizon">N/A</div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>

                                        {{-- Related Market Comparisons --}}
                                        <template x-for="comp in (impactData.comparisons || [])" :key="comp.symbol">
                                            <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-3 space-y-2"
                                                 :data-testid="'impact-comparison-' + comp.symbol">
                                                <div class="flex items-center justify-between text-xs">
                                                    <span class="font-semibold font-mono text-zinc-800 dark:text-zinc-200" x-text="comp.symbol + ' — ' + comp.name"></span>
                                                    <span class="font-mono text-[11px] text-zinc-500"
                                                          x-text="comp.baseline_available ? ('Baseline: $' + Number(comp.baseline_price).toFixed(2)) : 'Intraday N/A'"></span>
                                                </div>
                                                <div class="grid grid-cols-5 gap-1.5 text-center">
                                                    <template x-for="h in comp.horizons" :key="h.key">
                                                        <div class="p-1.5 rounded bg-zinc-50 dark:bg-zinc-800/60 border border-zinc-200/60 dark:border-zinc-800">
                                                            <div class="text-[10px] font-semibold text-zinc-500" x-text="h.key"></div>
                                                            <template x-if="h.available">
                                                                <div class="font-mono text-xs font-bold"
                                                                     :class="h.change_percent > 0 ? 'text-emerald-600 dark:text-emerald-400' : (h.change_percent < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-600')"
                                                                     x-text="(h.change_percent > 0 ? '+' : '') + Number(h.change_percent).toFixed(2) + '%'"></div>
                                                            </template>
                                                            <template x-if="!h.available">
                                                                <div class="text-[10px] text-zinc-400 font-mono">N/A</div>
                                                            </template>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>

                                        <p class="text-[11px] text-zinc-400 dark:text-zinc-500 italic" x-text="impactData.disclaimer"></p>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div x-show="!selectedNewsItem"
                         class="p-8 rounded-xl border border-dashed border-zinc-200 dark:border-zinc-800 text-center text-xs text-zinc-500">
                        {{ __('Select a news event from the timeline or click a marker on the chart to inspect event details and 5m / 15m / 30m / 60m / 4h price reactions.') }}
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- DuoAsset Setup Score Panel --}}
    @php
        $activeAlert = $activeSetupAlert ?? $latestSetupAlert;
    @endphp
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

            @if ($activeAlert)
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <div class="text-2xl font-black font-mono text-sky-600 dark:text-sky-400" data-testid="overall-setup-score">
                            {{ $activeAlert->setup_score }}
                        </div>
                        <div class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">
                            {{ __('Score (0-100)') }}
                        </div>
                    </div>
                </div>
            @endif
        </div>

        @if ($activeAlert)
            <div class="mt-6 space-y-6">
                @if ($setupAlertsByType && $setupAlertsByType->count() > 1)
                    <div class="space-y-2" data-testid="multi-setup-types">
                        <div class="flex items-center justify-between">
                            <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                {{ __('Detected Setup Types for Symbol') }}
                            </div>
                            <div class="text-[11px] text-zinc-400 dark:text-zinc-500">
                                {{ __('Click to switch active setup score & breakdown') }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2" role="tablist" aria-label="{{ __('Setup Types') }}">
                            @foreach ($setupAlertsByType as $alertItem)
                                @php
                                    $isSelected = ($alertItem->setup_type === ($selectedSetupType ?? $activeAlert->setup_type));
                                @endphp
                                <button type="button"
                                        wire:click="selectSetupType('{{ $alertItem->setup_type }}')"
                                        role="tab"
                                        aria-selected="{{ $isSelected ? 'true' : 'false' }}"
                                        data-testid="setup-type-toggle-{{ $alertItem->setup_type }}"
                                        class="group inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs font-mono transition-all duration-150 cursor-pointer focus:outline-hidden focus:ring-2 focus:ring-sky-500/50 {{ $isSelected ? 'border-sky-500 dark:border-sky-500 bg-sky-50 dark:bg-sky-950/70 text-sky-900 dark:text-sky-200 font-bold shadow-xs ring-1 ring-sky-400 dark:ring-sky-600' : 'border-zinc-200 dark:border-zinc-800 bg-zinc-50/80 dark:bg-zinc-800/40 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-700' }}">
                                    @if ($isSelected)
                                        <flux:icon icon="check" variant="micro" class="size-3 text-sky-600 dark:text-sky-400" />
                                    @endif
                                    <span>{{ $alertItem->setup_type }}</span>
                                    <span class="rounded px-1.5 py-0.2 text-[10px] font-bold {{ $isSelected ? 'bg-sky-200/80 dark:bg-sky-900 text-sky-900 dark:text-sky-200' : 'bg-sky-100 dark:bg-sky-950 text-sky-700 dark:text-sky-400 group-hover:bg-sky-200/60 dark:group-hover:bg-sky-900/60' }}">{{ $alertItem->setup_score }}/100</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Setup Summary Row --}}
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Setup Classification') }}</div>
                        <div class="mt-1 font-semibold text-sm text-zinc-900 dark:text-white" data-testid="setup-type">
                            {{ $activeAlert->setup_type }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Raw Score') }}</div>
                        <div class="mt-1 font-mono font-semibold text-sm text-zinc-900 dark:text-white" data-testid="raw-setup-score">
                            {{ $activeAlert->raw_setup_score ?? '—' }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Heartbeat') }}</div>
                        <div class="mt-1 font-mono font-semibold text-sm text-zinc-900 dark:text-white" data-testid="heartbeat-score">
                            {{ $activeAlert->heartbeat_score ?? '—' }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Base Duration') }}</div>
                        <div class="mt-1 font-mono font-semibold text-sm text-zinc-900 dark:text-white" data-testid="base-duration">
                            {{ $activeAlert->base_duration_days ? $activeAlert->base_duration_days . ' days' : '—' }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Range Compression') }}</div>
                        <div class="mt-1 font-mono font-semibold text-sm text-zinc-900 dark:text-white" data-testid="range-compression">
                            {{ $activeAlert->range_compression_pct !== null ? number_format((float) $activeAlert->range_compression_pct, 1) . '%' : '—' }}
                        </div>
                    </div>

                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200/60 dark:border-zinc-800">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Calculated At') }}</div>
                        <div class="mt-1 text-xs text-zinc-700 dark:text-zinc-300" data-testid="calculated-at">
                            {{ $activeAlert->detected_at ? \Carbon\Carbon::parse($activeAlert->detected_at)->toFormattedDateString() : '—' }}
                        </div>
                    </div>
                </div>

                {{-- Component Point Breakdown --}}
                @if (! empty($setupScoreBreakdown))
                    <div>
                        <h3 class="text-sm font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-3">
                            {{ __('Scoring Component Breakdown') }}
                        </h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3" data-testid="setup-score-breakdown-grid">
                            @foreach ($setupScoreBreakdown as $key => $component)
                                <div class="p-3 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/60 flex items-center justify-between"
                                     data-testid="breakdown-{{ $key }}">
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

                @if (! empty($activeAlert->reason_summary))
                    <div class="p-3.5 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-zinc-200 dark:border-zinc-800 text-xs text-zinc-600 dark:text-zinc-400" data-testid="setup-reason-summary">
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ __('Scoring Summary') }}:</span>
                        {{ $activeAlert->reason_summary }}
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
