<div class="space-y-6">
    <div class="flex items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ __('Watchlists') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Manage your watchlists and pick a default.') }}
            </p>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-700 dark:border-green-900 dark:bg-green-900/20 dark:text-green-300"
             dusk="flash">
            {{ session('status') }}
        </div>
    @endif

    <div class="da-card">
        <h2 class="text-base font-semibold mb-3">
            {{ $editingId ? __('Edit watchlist') : __('Create watchlist') }}
        </h2>

        <form wire:submit="save" class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="da-label">{{ __('Name') }}</label>
                <input type="text" wire:model="name" class="da-input" />
                @error('name') <p class="da-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="da-label">{{ __('Sector Label') }}</label>
                <input type="text" wire:model="sector_label" class="da-input" />
                @error('sector_label') <p class="da-error">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2">
                <label class="da-label">{{ __('Description') }}</label>
                <textarea wire:model="description" class="da-textarea"></textarea>
                @error('description') <p class="da-error">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2 flex items-center gap-2">
                <input type="checkbox" id="is_default" wire:model="is_default"
                       class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500" />
                <label for="is_default" class="text-sm">{{ __('Default watchlist') }}</label>
            </div>

            <div class="md:col-span-2 flex gap-2">
                <button type="submit" class="da-btn-primary">
                    {{ $editingId ? __('Update') : __('Create') }}
                </button>
                @if ($editingId)
                    <button type="button" wire:click="resetForm" class="da-btn-secondary">
                        {{ __('Cancel') }}
                    </button>
                @endif
            </div>
        </form>
    </div>

    <div class="da-card p-0 overflow-x-auto">
        <table class="da-table">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Default') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($watchlists as $w)
                <tr>
                    <td>
                        <a href="{{ route('watchlists.show', $w->id) }}"
                           class="font-medium text-indigo-600 hover:underline dark:text-sky-300">
                            {{ $w->name }}
                        </a>
                    </td>
                    <td>
                        @if ($w->is_default)
                            <span class="rounded bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-sky-900/40 dark:text-sky-300">
                                {{ __('Default') }}
                            </span>
                        @else
                            <span class="text-zinc-400">—</span>
                        @endif
                    </td>
                    <td class="text-right">
                        <div class="inline-flex gap-2">
                            <button type="button" wire:click="edit({{ $w->id }})"
                                    class="da-btn-secondary">{{ __('Edit') }}</button>
                            @unless ($w->is_default)
                                <button type="button" wire:click="setDefault({{ $w->id }})"
                                        class="da-btn-secondary">{{ __('Set Default') }}</button>
                            @endunless
                            <button type="button" wire:click="delete({{ $w->id }})"
                                    wire:confirm="{{ __('Delete this watchlist?') }}"
                                    class="da-btn-danger">{{ __('Delete') }}</button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-center text-zinc-500 py-6">
                        {{ __('No watchlists yet — create your first one above.') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
