import { VelaWorkspace } from '@luxalgo/vela/workspace';

// Keep track of active observer and resize listener per container
const containerRegistry = new WeakMap();

export function initStockChart(containerIdOrEl, options = {}) {
    const container = typeof containerIdOrEl === 'string'
        ? document.getElementById(containerIdOrEl)
        : containerIdOrEl;

    if (!container) {
        console.warn('Vela container not found:', containerIdOrEl);
        return null;
    }

    const {
        symbol = 'STOCK',
        candles = [],
        timeframe = '1D',
        theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light',
        persistKey = null,
        previousWorkspace = null,
    } = options;

    // Preserve previous workspace state if possible
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

    // Clean up previous observer if registered for this container
    const existingRegistration = containerRegistry.get(container);
    if (existingRegistration) {
        try {
            existingRegistration.observer?.disconnect();
            if (existingRegistration.resizeHandler) {
                window.removeEventListener('resize', existingRegistration.resizeHandler);
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
            drawingToolbar: true,
        };

        if (persistKey) {
            workspaceOptions.persist = persistKey;
        }

        const ws = new VelaWorkspace(container, workspaceOptions);
        container.__vela_ws = ws;

        // Restore previous chart state (drawings, indicators) if available
        if (savedState && typeof ws.applyState === 'function') {
            try {
                ws.applyState(savedState);
            } catch (e) {
                console.warn('Could not reapply saved Vela state:', e);
            }
        }

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

        containerRegistry.set(container, { observer, resizeHandler });

        return ws;
    } catch (e) {
        console.error('Error initializing VelaWorkspace:', e);
        return null;
    }
}

window.initStockChart = initStockChart;
