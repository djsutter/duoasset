import { VelaWorkspace } from '@luxalgo/vela/workspace';

/**
 * Note on Future Scripting Engine / Pine Script Compatibility:
 *
 * Vela's core charting engine and workspace are licensed under Apache-2.0.
 * Third-party execution engines such as PineTS or Vela-pinets are distributed
 * under AGPL-3.0, which imposes copyleft obligations incompatible with proprietary
 * application source code.
 *
 * To extend this chart with Pine Script-style indicators in the future without
 * introducing AGPL dependencies:
 * 1. Register a modular custom engine via VelaWorkspace's `engines` option
 *    (e.g., `engines: { customScript: () => new ProprietaryScriptEngine() }`),
 * 2. Alternatively, compute custom indicator series server-side using Laravel
 *    services and stream them as secondary data feeds or manifest indicators,
 * 3. Never bundle AGPL-licensed client-side interpreters into DuoAsset frontend assets.
 */

// Keep track of active observer and resize listener per container
const containerRegistry = new WeakMap();

export function initStockChart(containerIdOrEl, options = {}) {
    const rawContainer = typeof containerIdOrEl === 'string'
        ? document.getElementById(containerIdOrEl)
        : containerIdOrEl;

    if (!rawContainer) {
        console.warn('Vela container not found:', containerIdOrEl);
        return null;
    }

    // Mount inside dedicated child mount node if available to protect Alpine/Livewire overlay elements
    const container = rawContainer.querySelector?.('[data-vela-mount]') || rawContainer;

    const {
        symbol = 'STOCK',
        candles = [],
        timeframe = '1D',
        theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light',
        persistKey = null,
        previousWorkspace = null,
    } = options;

    // Preserve previous workspace state (indicators, drawings, styles) if available
    let savedState = null;
    const prevWs = previousWorkspace || container.__vela_ws;
    if (prevWs) {
        try {
            if (typeof prevWs.getState === 'function') {
                savedState = prevWs.getState();
            }
        } catch (e) {
            console.warn('Could not extract previous Vela state:', e);
        }
        try {
            if (typeof prevWs.destroy === 'function') {
                prevWs.destroy();
            }
        } catch (e) {
            console.warn('Could not destroy previous Vela instance:', e);
        }
    }

    // If no in-memory state from previousWorkspace was found, check localStorage for persisted state
    if (!savedState && persistKey && typeof window !== 'undefined' && window.localStorage) {
        try {
            const raw = localStorage.getItem(persistKey);
            if (raw) {
                savedState = JSON.parse(raw);
            }
        } catch (e) {
            // Ignore malformed persisted state
        }
    }

    // Clean up previous observer and listeners if registered for this container
    const existingRegistration = containerRegistry.get(container);
    if (existingRegistration) {
        try {
            existingRegistration.observer?.disconnect();
            if (existingRegistration.resizeHandler) {
                window.removeEventListener('resize', existingRegistration.resizeHandler);
            }
            if (existingRegistration.unloadHandler) {
                window.removeEventListener('beforeunload', existingRegistration.unloadHandler);
            }
            if (typeof existingRegistration.unsubState === 'function') {
                existingRegistration.unsubState();
            }
        } catch (e) {
            // ignore
        }
        containerRegistry.delete(container);
    }

    // Clear container children to ensure a clean mount surface
    container.innerHTML = '';

    // Convert candle seconds timestamp to milliseconds if required by Vela
    const formattedData = (Array.isArray(candles) ? candles : []).map((c) => ({
        time: c.time > 1e11 ? c.time : c.time * 1000,
        open: Number(c.open),
        high: Number(c.high),
        low: Number(c.low),
        close: Number(c.close),
        volume: c.volume !== undefined ? Number(c.volume) : 0,
    }));

    // If no candles are present, don't mount Vela; leave container ready for empty message
    if (formattedData.length === 0) {
        container.__vela_ws = null;
        return null;
    }

    try {
        const workspaceOptions = {
            layout: false, // Single-chart mode
            theme: theme === 'dark' ? 'dark' : 'light',
            symbol: symbol,
            timeframe: timeframe,
            data: formattedData,
            // Explicitly enable volume on workspace / chart options
            volume: true,
            // Disable built-in symbol search since DuoAsset uses its own navigation
            symbolSearch: false,
            // Native indicator picker enabled
            indicatorPicker: true,
            // Topbar configuration exposing style, indicator library button, and undo-redo
            topbar: {
                left: ['style', 'indicators', 'undo-redo'],
                right: ['screenshot'],
            },
            drawingToolbar: true,
            // Vela 0.9.0 uses statusline boolean option
            statusline: true,
            // Note: We intentionally omit workspaceOptions.persist (or set to false) because
            // Vela's built-in boot pool does not serialize offline candle data, which would
            // cause cell initialization to mount with empty data on page refresh.
            // Instead, we manage state persistence explicitly below via applyState / getState.
            persist: false,
        };

        const ws = new VelaWorkspace(container, workspaceOptions);
        container.__vela_ws = ws;
        if (rawContainer) {
            rawContainer.__vela_ws = ws;
        }

        // Restore previous chart state (indicators, drawings, settings) if available
        if (savedState) {
            try {
                // Synchronize symbol and timeframe on all saved chart cells so rehydrate()
                // does not revert the user's newly selected interval or trigger setMarket()
                // which would clear the offline candle data.
                if (Array.isArray(savedState.charts)) {
                    savedState.charts.forEach((chartCell) => {
                        chartCell.symbol = symbol;
                        chartCell.timeframe = timeframe;
                    });
                }

                if (typeof ws.applyState === 'function') {
                    ws.applyState(savedState);
                }
            } catch (e) {
                console.warn('Could not reapply saved Vela state:', e);
            }
        }

        // Helper to persist current workspace state (including active indicators and drawings)
        const saveState = () => {
            try {
                if (!ws.destroyed && typeof ws.getState === 'function' && persistKey && typeof window !== 'undefined' && window.localStorage) {
                    const currentState = ws.getState();
                    if (currentState) {
                        localStorage.setItem(persistKey, JSON.stringify(currentState));
                    }
                }
            } catch (e) {
                // Ignore storage errors
            }
        };

        // Listen for workspace state changes (indicator added/configured/removed, drawings, etc.)
        let unsubState = null;
        if (typeof ws.on === 'function') {
            try {
                unsubState = ws.on('state:changed', saveState);
            } catch (e) {
                // fallback
            }
        }

        const unloadHandler = () => {
            saveState();
        };
        window.addEventListener('beforeunload', unloadHandler);

        // Sync theme changes with the application
        const observer = new MutationObserver(() => {
            const isDark = document.documentElement.classList.contains('dark');
            try {
                ws.setTheme(isDark ? 'dark' : 'light');
            } catch (e) {
                // ignore
            }
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        const resizeHandler = () => {
            try {
                ws.resize();
            } catch (e) {
                // ignore
            }
        };
        window.addEventListener('resize', resizeHandler);

        // Hook into destroy to ensure state is flushed and event listeners detached
        const originalDestroy = ws.destroy.bind(ws);
        ws.destroy = function () {
            try {
                saveState();
            } catch (e) {}
            if (typeof unsubState === 'function') {
                try { unsubState(); } catch (e) {}
            }
            window.removeEventListener('beforeunload', unloadHandler);
            window.removeEventListener('resize', resizeHandler);
            try { observer.disconnect(); } catch (e) {}
            containerRegistry.delete(container);
            container.__vela_ws = null;
            if (rawContainer) {
                rawContainer.__vela_ws = null;
            }
            return originalDestroy();
        };

        containerRegistry.set(container, { observer, resizeHandler, unloadHandler, unsubState });

        return ws;
    } catch (e) {
        console.error('Error initializing VelaWorkspace:', e);
        if (typeof options.onError === 'function') {
            options.onError(e);
        }
        return null;
    }
}

window.initStockChart = initStockChart;
