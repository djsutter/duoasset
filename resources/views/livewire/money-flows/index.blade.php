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

    $changeClass = function ($value): string {
        $v = (float) $value;
        if ($v > 0) return 'text-emerald-600 dark:text-emerald-400';
        if ($v < 0) return 'text-rose-600 dark:text-rose-400';
        return 'text-zinc-500 dark:text-zinc-400';
    };

    $signed = fn ($value) => ($value === null) ? '—' : (($value > 0 ? '+' : '').number_format((float) $value, 2));
@endphp

<div wire:poll.60s>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Money Flows') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Estimated institutional money flow across the major North American sector ETFs.') }}
                @if ($latestCapturedAt)
                    <span class="ml-1">{{ __('Updated') }} {{ \Illuminate\Support\Carbon::parse($latestCapturedAt)->diffForHumans() }}.</span>
                @endif
            </p>
        </div>

        {{-- Cadence toggle: end-of-day vs intraday hourly. --}}
        <div class="inline-flex overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <button type="button" wire:click="setInterval('eod')"
                class="px-3 py-1.5 text-sm {{ $interval === 'eod' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300' }}">
                {{ __('End of day') }}
            </button>
            <button type="button" wire:click="setInterval('hourly')"
                class="px-3 py-1.5 text-sm {{ $interval === 'hourly' ? 'bg-sky-600 text-white' : 'bg-white text-zinc-600 dark:bg-zinc-900 dark:text-zinc-300' }}">
                {{ __('Hourly') }}
            </button>
        </div>
    </div>

    @php
        $header = function (string $label, string $column) use ($sortBy, $sortDirection) {
            $active = $sortBy === $column;
            $arrow = $active ? ($sortDirection === 'asc' ? '▲' : '▼') : '';
            return [$label, $column, $active, $arrow];
        };
    @endphp

    <div class="da-card overflow-x-auto">
        <table class="da-table">
            <thead>
                <tr>
                    @foreach ([
                        ['Sector', 'sector'],
                        ['Strength', 'strength'],
                        ['1H', 'hourly_change_pct'],
                        ['1D', 'daily_change_pct'],
                        ['1W', 'weekly_change_pct'],
                        ['1M', 'monthly_change_pct'],
                        ['Velocity', 'velocity'],
                        ['Acceleration', 'acceleration'],
                        ['Breadth', 'issuer_breadth_weekly'],
                    ] as [$label, $column])
                        <th class="{{ in_array($column, ['sector']) ? 'text-left' : 'text-right' }}">
                            <button type="button" wire:click="sortByColumn('{{ $column }}')"
                                class="inline-flex items-center gap-1 font-semibold hover:text-sky-600 dark:hover:text-sky-400">
                                {{ __($label) }}
                                @if ($sortBy === $column)
                                    <span class="text-xs">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                @endif
                            </button>
                        </th>
                    @endforeach
                    <th class="text-left">{{ __('Direction') }}</th>
                    <th class="text-right">{{ __('Rank') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($snapshots as $s)
                    <tr>
                        <td class="font-medium">{{ $s->label ?? $s->sector }}</td>
                        <td class="text-right tabular-nums font-semibold">
                            {{ $s->strength === null ? '—' : number_format((float) $s->strength, 1) }}
                        </td>
                        <td class="text-right tabular-nums {{ $changeClass($s->hourly_change_pct) }}">{{ $signed($s->hourly_change_pct) }}</td>
                        <td class="text-right tabular-nums {{ $changeClass($s->daily_change_pct) }}">{{ $signed($s->daily_change_pct) }}</td>
                        <td class="text-right tabular-nums {{ $changeClass($s->weekly_change_pct) }}">{{ $signed($s->weekly_change_pct) }}</td>
                        <td class="text-right tabular-nums {{ $changeClass($s->monthly_change_pct) }}">{{ $signed($s->monthly_change_pct) }}</td>
                        <td class="text-right tabular-nums {{ $changeClass($s->velocity) }}">{{ $signed($s->velocity) }}</td>
                        <td class="text-right tabular-nums {{ $changeClass($s->acceleration) }}">{{ $signed($s->acceleration) }}</td>
                        <td class="text-right tabular-nums">
                            {{ $s->issuer_breadth_weekly === null ? '—' : number_format((float) $s->issuer_breadth_weekly, 0).'%' }}
                        </td>
                        <td>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium {{ $directionClasses($s->direction) }}">
                                {{ ucfirst($s->direction ?? 'stable') }}
                            </span>
                        </td>
                        <td class="text-right tabular-nums text-zinc-500 dark:text-zinc-400">{{ $s->rank ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('No money-flow snapshots yet. Run') }} <code>php artisan moneyflow:update</code>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-xs text-zinc-400 dark:text-zinc-500">
        {{ __('Money-flow proxy derived from sector ETF price and volume — not verified ETF net creations or redemptions.') }}
    </p>
</div>
