@props([
    'paginator',
    'itemName' => 'results',
])

@php
    $isUnderflow = $paginator->lastPage() < 4;
@endphp

<div
    class="rounded-xl border border-amber-300 bg-amber-50/90 p-5 shadow-xs dark:border-amber-700/60 dark:bg-amber-950/30"
    data-testid="{{ $isUnderflow ? 'guest-preview-underflow-banner' : 'guest-preview-banner' }}"
    role="region"
    aria-label="{{ __('Guest Preview Notice') }}"
>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div class="flex items-start gap-3">
            <div class="rounded-lg bg-amber-500/10 p-2 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400 shrink-0">
                @if ($isUnderflow)
                    <flux:icon icon="information-circle" variant="mini" class="size-5" />
                @else
                    <flux:icon icon="sparkles" variant="mini" class="size-5" />
                @endif
            </div>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white" data-testid="guest-preview-title">
                        @if ($isUnderflow)
                            {{ __('Guest Preview: Sample Status') }}
                        @else
                            {{ __('Guest Preview: Page 4 Sample Results') }}
                        @endif
                    </h3>
                    <span class="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/50 dark:text-amber-300" data-testid="guest-preview-badge">
                        @if ($isUnderflow)
                            {{ __('Sample Status') }}
                        @else
                            {{ __('Page 4 Clamped') }}
                        @endif
                    </span>
                </div>
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400" data-testid="guest-preview-description">
                    @if ($isUnderflow)
                        {{ __('Guest preview is strictly clamped to page 4. The current dataset contains :count record(s) across :pages page(s), so page 4 contains no sample entries. Subscribe to access all pages and full scoring results.', ['count' => $paginator->total(), 'pages' => $paginator->lastPage()]) }}
                    @else
                        {{ __('You are viewing live sample records strictly from page 4 of the scoring rankings. Multi-page pagination is disabled for guest accounts. Subscribe to unlock pages 1–3, real-time setups, and full scoring history.') }}
                    @endif
                </p>
            </div>
        </div>

        <div class="shrink-0 w-full sm:w-auto">
            <a
                href="#subscribe"
                class="inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-lg bg-amber-500 hover:bg-amber-600 px-4 py-2 text-xs font-semibold text-white shadow-xs focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600 transition"
                role="button"
                aria-label="{{ __('Subscribe to DuoAsset for Full Access') }}"
                data-testid="guest-preview-subscribe-button"
            >
                <flux:icon icon="sparkles" variant="mini" class="size-4" />
                <span>{{ __('Subscribe for Full Access') }}</span>
            </a>
        </div>
    </div>
</div>
