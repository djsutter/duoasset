<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Stocks') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Browse all stocks tracked across watchlists.') }}
            </p>
        </div>
        <div>
            <a href="{{ route('watchlists.index') }}"
               class="da-btn da-btn-secondary">{{ __('Go to Watchlists') }}</a>
        </div>
    </div>

    <div class="da-card">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-6">
            <div class="lg:col-span-2">
                <label class="da-label">{{ __('Search') }}</label>
                <input type="search"
                       wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('Symbol or company name') }}"
                       class="da-input" />
            </div>

            <div>
                <label class="da-label">{{ __('Exchange') }}</label>
                <select wire:model.live="filterExchange" class="da-input">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($exchanges as $e)
                        <option value="{{ $e->value }}">{{ $e->value }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Currency') }}</label>
                <select wire:model.live="filterCurrency" class="da-input">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($currencies as $c)
                        <option value="{{ $c->value }}">{{ $c->value }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Sector') }}</label>
                <select wire:model.live="filterSectorId" class="da-input">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($sectors as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Industry') }}</label>
                <select wire:model.live="filterIndustryId" class="da-input">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($industries as $i)
                        <option value="{{ $i->id }}">{{ $i->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Sub-Industry') }}</label>
                <select wire:model.live="filterSubIndustryId" class="da-input">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($subIndustries as $si)
                        <option value="{{ $si->id }}">{{ $si->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-3 flex justify-end">
            <button type="button" wire:click="clearFilters"
                    class="da-btn da-btn-ghost">{{ __('Clear filters') }}</button>
        </div>
    </div>

    <div class="da-card p-0 overflow-x-auto">
        <table class="da-table">
            <thead>
                <tr>
                    <th><button type="button" wire:click="sortBy('symbol')" class="da-sort">{{ __('Symbol') }}</button></th>
                    <th><button type="button" wire:click="sortBy('company_name')" class="da-sort">{{ __('Company') }}</button></th>
                    <th><button type="button" wire:click="sortBy('exchange')" class="da-sort">{{ __('Exchange') }}</button></th>
                    <th><button type="button" wire:click="sortBy('currency')" class="da-sort">{{ __('Currency') }}</button></th>
                    <th><button type="button" wire:click="sortBy('sector')" class="da-sort">{{ __('Sector') }}</button></th>
                    <th><button type="button" wire:click="sortBy('industry')" class="da-sort">{{ __('Industry') }}</button></th>
                    <th><button type="button" wire:click="sortBy('sub_industry')" class="da-sort">{{ __('Sub-Industry') }}</button></th>
                    <th class="text-right">{{ __('Last') }}</th>
                    <th class="text-right"><button type="button" wire:click="sortBy('daily_change')" class="da-sort">{{ __('Change') }}</button></th>
                    <th class="text-right"><button type="button" wire:click="sortBy('daily_change_percent')" class="da-sort">{{ __('Change %') }}</button></th>
                    <th class="text-right">{{ __('Volume') }}</th>
                    <th><button type="button" wire:click="sortBy('last_checked_at')" class="da-sort">{{ __('Checked') }}</button></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($stocks as $stock)
                    <tr>
                        <td class="font-mono font-semibold">{{ $stock->symbol }}</td>
                        <td>{{ $stock->company_name }}</td>
                        <td>{{ $stock->exchange->value }}</td>
                        <td>{{ $stock->currency->value }}</td>
                        <td>{{ $stock->sector?->name }}</td>
                        <td>{{ $stock->industry?->name }}</td>
                        <td>{{ $stock->subIndustry?->name }}</td>
                        <td class="text-right font-mono">@money($stock->last_price)</td>
                        <td class="text-right font-mono @if($stock->daily_change && $stock->daily_change->isNegative()) text-red-600 @elseif($stock->daily_change && $stock->daily_change->isPositive()) text-green-600 @endif">@money($stock->daily_change)</td>
                        <td class="text-right font-mono @if($stock->daily_change_percent !== null && $stock->daily_change_percent < 0) text-red-600 @elseif($stock->daily_change_percent !== null && $stock->daily_change_percent > 0) text-green-600 @endif">
                            @if($stock->daily_change_percent !== null)
                                {{ number_format($stock->daily_change_percent / 10000, 2) }}%
                            @endif
                        </td>
                        <td class="text-right font-mono">{{ $stock->volume !== null ? number_format($stock->volume) : '' }}</td>
                        <td class="text-xs text-zinc-500">{{ $stock->last_checked_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="text-center text-zinc-500 py-6">
                            {{ __('No stocks found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>
        {{ $stocks->links() }}
    </div>
</div>
