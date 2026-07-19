@php
    use App\Enums\SectorFlowDirection;

    $directionClasses = function (?string $direction): string {
        return match ($direction) {
            SectorFlowDirection::Accelerating->value => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-200',
            SectorFlowDirection::Improving->value => 'bg-sky-100 text-sky-700 dark:bg-sky-900/60 dark:text-sky-200',
            SectorFlowDirection::Cooling->value => 'bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-200',
            SectorFlowDirection::Weakening->value => 'bg-rose-100 text-rose-700 dark:bg-rose-900/60 dark:text-rose-200',
            default => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300',
        };
    };
    $signed = fn ($value) => ($value === null) ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 2));
@endphp

<div wire:poll.120s x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    {{-- Compact summary card. Clicking opens the full-ranking modal. --}}
    <button type="button" x-on:click="open = true"
        class="w-full rounded-2xl border border-zinc-200 bg-white p-4 text-left shadow-sm transition hover:border-sky-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-sky-700">
        <div class="flex items-center justify-between">
            <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ __('Money Flows') }}</span>
            @if ($latestCapturedAt)
                <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ \Illuminate\Support\Carbon::parse($latestCapturedAt)->diffForHumans() }}</span>
            @endif
        </div>

        @if ($ranked->isEmpty())
            <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">{{ __('No snapshots yet.') }}</p>
        @else
            <div class="mt-3 space-y-2 text-sm">
                <div>
                    <span class="text-xs uppercase tracking-wide text-zinc-400">{{ __('Leading') }}</span>
                    <div class="mt-1 flex flex-wrap gap-1.5">
                        @foreach ($leading as $s)
                            <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                {{ $s->label ?? $s->sector }} {{ $s->strength === null ? '' : number_format((float) $s->strength, 0) }}
                            </span>
                        @endforeach
                    </div>
                </div>

                @if ($accelerating->isNotEmpty())
                    <div>
                        <span class="text-xs uppercase tracking-wide text-emerald-500">{{ __('Accelerating') }} ↑</span>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            @foreach ($accelerating as $s)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-200">
                                    {{ $s->label ?? $s->sector }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($cooling->isNotEmpty())
                    <div>
                        <span class="text-xs uppercase tracking-wide text-amber-500">{{ __('Cooling') }} ↓</span>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            @foreach ($cooling as $s)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/60 dark:text-amber-200">
                                    {{ $s->label ?? $s->sector }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
            <p class="mt-3 text-xs text-sky-600 dark:text-sky-400">{{ __('View full ranking →') }}</p>
        @endif
    </button>

    {{-- Full-ranking modal (client-side; data already loaded). --}}
    <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <div x-show="open" x-transition.opacity class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm" x-on:click="open = false"></div>

        <div x-show="open" x-transition
            class="relative w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-zinc-950">
            <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-3 dark:border-zinc-800">
                <h2 class="text-lg font-semibold">{{ __('Sector Money Flows — full ranking') }}</h2>
                <button type="button" x-on:click="open = false" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">✕</button>
            </div>

            <div class="max-h-[70vh] overflow-y-auto p-5">
                <table class="da-table">
                    <thead>
                        <tr>
                            <th class="text-right">#</th>
                            <th class="text-left">{{ __('Sector') }}</th>
                            <th class="text-right">{{ __('Strength') }}</th>
                            <th class="text-right">{{ __('1D') }}</th>
                            <th class="text-right">{{ __('1M') }}</th>
                            <th class="text-left">{{ __('Direction') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ranked as $s)
                            <tr>
                                <td class="text-right tabular-nums text-zinc-500">{{ $s->rank ?? $loop->iteration }}</td>
                                <td class="font-medium">{{ $s->label ?? $s->sector }}</td>
                                <td class="text-right tabular-nums font-semibold">{{ $s->strength === null ? '—' : number_format((float) $s->strength, 1) }}</td>
                                <td class="text-right tabular-nums">{{ $signed($s->daily_change_pct) }}</td>
                                <td class="text-right tabular-nums">{{ $signed($s->monthly_change_pct) }}</td>
                                <td>
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $directionClasses($s->direction) }}">
                                        {{ ucfirst($s->direction ?? 'stable') }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="mt-4 text-right">
                    <a href="{{ route('money-flows.index') }}" wire:navigate class="text-sm text-sky-600 hover:underline dark:text-sky-400">
                        {{ __('Open Money Flows dashboard →') }}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
