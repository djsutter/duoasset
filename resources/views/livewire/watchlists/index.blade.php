<div>
    <h1>{{ __('Watchlists') }}</h1>

    @if (session('status'))
        <div class="text-green-600" dusk="flash">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="my-4 space-y-2">
        <div>
            <label>{{ __('Name') }}</label>
            <input type="text" wire:model="name" />
            @error('name') <span class="text-red-600">{{ $message }}</span> @enderror
        </div>
        <div>
            <label>{{ __('Description') }}</label>
            <textarea wire:model="description"></textarea>
        </div>
        <div>
            <label>{{ __('Sector Label') }}</label>
            <input type="text" wire:model="sector_label" />
        </div>
        <div>
            <label>
                <input type="checkbox" wire:model="is_default" /> {{ __('Default') }}
            </label>
        </div>
        <div>
            <button type="submit">{{ $editingId ? __('Update') : __('Create') }}</button>
            @if ($editingId)
                <button type="button" wire:click="resetForm">{{ __('Cancel') }}</button>
            @endif
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Default') }}</th>
                <th>{{ __('Actions') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($watchlists as $w)
            <tr>
                <td>
                    <a href="{{ route('watchlists.show', $w->id) }}">{{ $w->name }}</a>
                </td>
                <td>{{ $w->is_default ? __('Yes') : __('No') }}</td>
                <td>
                    <button type="button" wire:click="edit({{ $w->id }})">{{ __('Edit') }}</button>
                    @unless ($w->is_default)
                        <button type="button" wire:click="setDefault({{ $w->id }})">{{ __('Set Default') }}</button>
                    @endunless
                    <button type="button" wire:click="delete({{ $w->id }})">{{ __('Delete') }}</button>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
