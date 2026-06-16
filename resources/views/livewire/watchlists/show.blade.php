<div class="space-y-6">
    <div>
        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
            <a href="{{ route('watchlists.index') }}" class="hover:underline">{{ __('Watchlists') }}</a>
            <span>/</span>
            <span>{{ $watchlist->name }}</span>
        </div>
        <h1 class="text-2xl font-semibold">{{ $watchlist->name }}</h1>
        @if ($watchlist->description)
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $watchlist->description }}</p>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-700 dark:border-green-900 dark:bg-green-900/20 dark:text-green-300"
             dusk="flash">
            {{ session('status') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="da-card">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
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
                    <option value="">{{ __('All Exchanges') }}</option>
                    @foreach ($exchanges as $e)
                        <option value="{{ $e->value }}">{{ $e->value }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Currency') }}</label>
                <select wire:model.live="filterCurrency" class="da-input">
                    <option value="">{{ __('All Currencies') }}</option>
                    @foreach ($currencies as $c)
                        <option value="{{ $c->value }}">{{ $c->value }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Sector') }}</label>
                <select wire:model.live="filterSectorId" class="da-input">
                    <option value="">{{ __('All Sectors') }}</option>
                    @foreach ($sectors as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Industry') }}</label>
                <select wire:model.live="filterIndustryId" class="da-input">
                    <option value="">{{ __('All Industries') }}</option>
                    @foreach ($industries as $i)
                        <option value="{{ $i->id }}">{{ $i->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Sub-Industry') }}</label>
                <select wire:model.live="filterSubIndustryId" class="da-input">
                    <option value="">{{ __('All Sub-Industries') }}</option>
                    @foreach ($subIndustries as $si)
                        <option value="{{ $si->id }}">{{ $si->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="da-label">{{ __('Moat Level') }}</label>
                <select wire:model.live="filterMoatLevel" class="da-input">
                    <option value="">{{ __('All Moat Levels') }}</option>
                    @foreach ($moatLevels as $m)
                        <option value="{{ $m->value }}">{{ $m->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-3 flex justify-end gap-2">
            <button type="button" wire:click="clearFilters" class="da-btn-ghost">
                {{ __('Clear filters') }}
            </button>
            @if (! $showAddForm)
                <button type="button" wire:click="openAddForm" class="da-btn-primary">
                    {{ __('Add Stock') }}
                </button>
            @endif
        </div>
    </div>

    {{-- Add stock form --}}
    @if ($showAddForm)
        <form wire:submit="addStock" class="da-card space-y-4">
            <h2 class="text-base font-semibold">{{ __('Add stock to watchlist') }}</h2>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="da-label">{{ __('Symbol') }}</label>
                    <input type="text" wire:model="symbol" class="da-input" />
                    @error('symbol') <p class="da-error">{{ $message }}</p> @enderror
                </div>
                <div class="lg:col-span-2">
                    <label class="da-label">{{ __('Company Name') }}</label>
                    <input type="text" wire:model="company_name" class="da-input" />
                    @error('company_name') <p class="da-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="da-label">{{ __('Exchange') }}</label>
                    <select wire:model="exchange" class="da-input">
                        <option value="">--</option>
                        @foreach ($exchanges as $e)
                            <option value="{{ $e->value }}">{{ $e->value }}</option>
                        @endforeach
                    </select>
                    @error('exchange') <p class="da-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="da-label">{{ __('Currency') }}</label>
                    <select wire:model="currency" class="da-input">
                        <option value="">--</option>
                        @foreach ($currencies as $c)
                            <option value="{{ $c->value }}">{{ $c->value }}</option>
                        @endforeach
                    </select>
                    @error('currency') <p class="da-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="da-label">{{ __('Moat Level') }}</label>
                    <select wire:model="moat_level" class="da-input">
                        <option value="">--</option>
                        @foreach ($moatLevels as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                    @error('moat_level') <p class="da-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="da-label">{{ __('Sector') }}</label>
                    <select wire:model="sector_id" class="da-input">
                        <option value="">--</option>
                        @foreach ($sectors as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                    @error('sector_id') <p class="da-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="da-label">{{ __('Industry') }}</label>
                    <select wire:model="industry_id" class="da-input">
                        <option value="">--</option>
                        @foreach ($industries as $i)
                            <option value="{{ $i->id }}">{{ $i->name }}</option>
                        @endforeach
                    </select>
                    @error('industry_id') <p class="da-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="da-label">{{ __('Sub-Industry') }}</label>
                    <select wire:model="sub_industry_id" class="da-input">
                        <option value="">--</option>
                        @foreach ($subIndustries as $si)
                            <option value="{{ $si->id }}">{{ $si->name }}</option>
                        @endforeach
                    </select>
                    @error('sub_industry_id') <p class="da-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="da-label">{{ __('Target Price') }}</label>
                    <input type="text" wire:model="target_price" class="da-input" />
                    @error('target_price') <p class="da-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="da-label">{{ __('Stop Price') }}</label>
                    <input type="text" wire:model="stop_price" class="da-input" />
                    @error('stop_price') <p class="da-error">{{ $message }}</p> @enderror
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <label class="da-label">{{ __('Thesis') }}</label>
                    <textarea wire:model="thesis" class="da-textarea"></textarea>
                </div>
                <div class="md:col-span-2 lg:col-span-3">
                    <label class="da-label">{{ __('Notes') }}</label>
                    <textarea wire:model="notes" class="da-textarea"></textarea>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="da-btn-primary">{{ __('Add') }}</button>
                <button type="button" wire:click="cancelAddForm" class="da-btn-secondary">{{ __('Cancel') }}</button>
            </div>
        </form>
    @endif

    {{-- Items table --}}
    <div class="da-card p-0 overflow-x-auto">
        <table class="da-table">
            <thead>
                <tr>
                    <th><button type="button" wire:click="sortBy('symbol')" class="da-sort">{{ __('Symbol') }}</button></th>
                    <th>{{ __('Exchange') }}</th>
                    <th>{{ __('Currency') }}</th>
                    <th><button type="button" wire:click="sortBy('company_name')" class="da-sort">{{ __('Company Name') }}</button></th>
                    <th><button type="button" wire:click="sortBy('sector')" class="da-sort">{{ __('Sector') }}</button></th>
                    <th><button type="button" wire:click="sortBy('industry')" class="da-sort">{{ __('Industry') }}</button></th>
                    <th><button type="button" wire:click="sortBy('sub_industry')" class="da-sort">{{ __('Sub-Industry') }}</button></th>
                    <th><button type="button" wire:click="sortBy('moat_level')" class="da-sort">{{ __('Moat Level') }}</button></th>
                    <th class="text-right"><button type="button" wire:click="sortBy('target_price')" class="da-sort">{{ __('Target') }}</button></th>
                    <th class="text-right"><button type="button" wire:click="sortBy('stop_price')" class="da-sort">{{ __('Stop') }}</button></th>
                    <th>{{ __('Notes') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($items as $item)
                <tr>
                    <td class="font-mono font-semibold">{{ $item->stock->symbol }}</td>
                    <td>{{ $item->stock->exchange->value }}</td>
                    <td>{{ $item->stock->currency->value }}</td>
                    <td>{{ $item->stock->company_name }}</td>
                    <td>{{ $item->stock->sector?->name }}</td>
                    <td>{{ $item->stock->industry?->name }}</td>
                    <td>{{ $item->stock->subIndustry?->name }}</td>
                    <td>{{ $item->moat_level->label() }}</td>
                    <td class="text-right">@money($item->target_price)</td>
                    <td class="text-right">@money($item->stop_price)</td>
                    <td class="max-w-xs truncate">{{ $item->notes }}</td>
                    <td class="text-right">
                        <button type="button"
                                wire:click="removeItem({{ $item->id }})"
                                wire:confirm="{{ __('Remove this stock?') }}"
                                class="da-btn-danger">{{ __('Remove') }}</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center text-zinc-500 py-6">
                        {{ __('No stocks in this watchlist yet.') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
