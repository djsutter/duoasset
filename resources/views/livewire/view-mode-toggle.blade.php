<div>
    @if ($canToggle)
        <div
            class="inline-flex items-center rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700"
            role="group"
            aria-label="{{ __('Perspective Switcher') }}"
        >
            <button
                type="button"
                wire:click="setViewMode('stocks')"
                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all {{ $viewMode === 'stocks' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                aria-pressed="{{ $viewMode === 'stocks' ? 'true' : 'false' }}"
                data-testid="view-mode-stocks"
            >
                <flux:icon icon="chart-bar" variant="mini" class="size-3.5" />
                <span>{{ __('Stocks') }}</span>
            </button>

            <button
                type="button"
                wire:click="setViewMode('crypto')"
                class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition-all {{ $viewMode === 'crypto' ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' }}"
                aria-pressed="{{ $viewMode === 'crypto' ? 'true' : 'false' }}"
                data-testid="view-mode-crypto"
            >
                <flux:icon icon="currency-dollar" variant="mini" class="size-3.5" />
                <span>{{ __('Crypto') }}</span>
            </button>
        </div>
    @endif
</div>
