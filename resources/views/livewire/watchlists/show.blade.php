<div>
    <h1>{{ $watchlist->name }}</h1>

    @if ($watchlist->description)
        <p>{{ $watchlist->description }}</p>
    @endif

    @if (session('status'))
        <div class="text-green-600" dusk="flash">{{ session('status') }}</div>
    @endif

    <div class="filters my-3 flex flex-wrap gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search symbol or company') }}" />

        <select wire:model.live="filterExchange">
            <option value="">{{ __('All Exchanges') }}</option>
            @foreach ($exchanges as $e)
                <option value="{{ $e->value }}">{{ $e->value }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterCurrency">
            <option value="">{{ __('All Currencies') }}</option>
            @foreach ($currencies as $c)
                <option value="{{ $c->value }}">{{ $c->value }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterSectorId">
            <option value="">{{ __('All Sectors') }}</option>
            @foreach ($sectors as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterIndustryId">
            <option value="">{{ __('All Industries') }}</option>
            @foreach ($industries as $i)
                <option value="{{ $i->id }}">{{ $i->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterSubIndustryId">
            <option value="">{{ __('All Sub-Industries') }}</option>
            @foreach ($subIndustries as $si)
                <option value="{{ $si->id }}">{{ $si->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="filterMoatLevel">
            <option value="">{{ __('All Moat Levels') }}</option>
            @foreach ($moatLevels as $m)
                <option value="{{ $m->value }}">{{ $m->label() }}</option>
            @endforeach
        </select>

        <button type="button" wire:click="clearFilters">{{ __('Clear') }}</button>
    </div>

    <div class="my-3">
        @if (! $showAddForm)
            <button type="button" wire:click="openAddForm">{{ __('Add Stock') }}</button>
        @endif
    </div>

    @if ($showAddForm)
        <form wire:submit="addStock" class="my-3 space-y-2 border p-3">
            <div>
                <label>{{ __('Symbol') }}</label>
                <input type="text" wire:model="symbol" />
                @error('symbol') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Company Name') }}</label>
                <input type="text" wire:model="company_name" />
                @error('company_name') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Exchange') }}</label>
                <select wire:model="exchange">
                    <option value="">--</option>
                    @foreach ($exchanges as $e)
                        <option value="{{ $e->value }}">{{ $e->value }}</option>
                    @endforeach
                </select>
                @error('exchange') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Currency') }}</label>
                <select wire:model="currency">
                    <option value="">--</option>
                    @foreach ($currencies as $c)
                        <option value="{{ $c->value }}">{{ $c->value }}</option>
                    @endforeach
                </select>
                @error('currency') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Sector') }}</label>
                <select wire:model="sector_id">
                    <option value="">--</option>
                    @foreach ($sectors as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
                @error('sector_id') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Industry') }}</label>
                <select wire:model="industry_id">
                    <option value="">--</option>
                    @foreach ($industries as $i)
                        <option value="{{ $i->id }}">{{ $i->name }}</option>
                    @endforeach
                </select>
                @error('industry_id') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Sub-Industry') }}</label>
                <select wire:model="sub_industry_id">
                    <option value="">--</option>
                    @foreach ($subIndustries as $si)
                        <option value="{{ $si->id }}">{{ $si->name }}</option>
                    @endforeach
                </select>
                @error('sub_industry_id') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Moat Level') }}</label>
                <select wire:model="moat_level">
                    <option value="">--</option>
                    @foreach ($moatLevels as $m)
                        <option value="{{ $m->value }}">{{ $m->label() }}</option>
                    @endforeach
                </select>
                @error('moat_level') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Target Price') }}</label>
                <input type="text" wire:model="target_price" />
                @error('target_price') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Stop Price') }}</label>
                <input type="text" wire:model="stop_price" />
                @error('stop_price') <span class="text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label>{{ __('Thesis') }}</label>
                <textarea wire:model="thesis"></textarea>
            </div>
            <div>
                <label>{{ __('Notes') }}</label>
                <textarea wire:model="notes"></textarea>
            </div>
            <div>
                <button type="submit">{{ __('Add') }}</button>
                <button type="button" wire:click="cancelAddForm">{{ __('Cancel') }}</button>
            </div>
        </form>
    @endif

    <table>
        <thead>
            <tr>
                <th><button type="button" wire:click="sortBy('symbol')">{{ __('Symbol') }}</button></th>
                <th>{{ __('Exchange') }}</th>
                <th>{{ __('Currency') }}</th>
                <th><button type="button" wire:click="sortBy('company_name')">{{ __('Company Name') }}</button></th>
                <th><button type="button" wire:click="sortBy('sector')">{{ __('Sector') }}</button></th>
                <th><button type="button" wire:click="sortBy('industry')">{{ __('Industry') }}</button></th>
                <th><button type="button" wire:click="sortBy('sub_industry')">{{ __('Sub-Industry') }}</button></th>
                <th><button type="button" wire:click="sortBy('moat_level')">{{ __('Moat Level') }}</button></th>
                <th><button type="button" wire:click="sortBy('target_price')">{{ __('Target Price') }}</button></th>
                <th><button type="button" wire:click="sortBy('stop_price')">{{ __('Stop Price') }}</button></th>
                <th>{{ __('Notes') }}</th>
                <th>{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>{{ $item->stock->symbol }}</td>
                <td>{{ $item->stock->exchange->value }}</td>
                <td>{{ $item->stock->currency->value }}</td>
                <td>{{ $item->stock->company_name }}</td>
                <td>{{ $item->stock->sector?->name }}</td>
                <td>{{ $item->stock->industry?->name }}</td>
                <td>{{ $item->stock->subIndustry?->name }}</td>
                <td>{{ $item->moat_level->label() }}</td>
                <td>@money($item->target_price)</td>
                <td>@money($item->stop_price)</td>
                <td>{{ $item->notes }}</td>
                <td>
                    <button type="button" wire:click="removeItem({{ $item->id }})">{{ __('Remove') }}</button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
