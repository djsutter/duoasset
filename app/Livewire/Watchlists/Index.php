<?php

namespace App\Livewire\Watchlists;

use App\Models\Watchlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public ?string $description = null;

    public ?string $sector_label = null;

    public bool $is_default = false;

    public bool $is_global = false;

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('watchlists', 'name')
                    ->where('user_id', auth()->id())
                    ->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'sector_label' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
            'is_global' => ['boolean'],
        ];
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = null;
        $this->sector_label = null;
        $this->is_default = false;
        $this->is_global = false;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $watchlist = Watchlist::findOrFail($id);
        $this->authorize('update', $watchlist);

        $this->editingId = $watchlist->id;
        $this->name = $watchlist->name;
        $this->description = $watchlist->description;
        $this->sector_label = $watchlist->sector_label;
        $this->is_default = (bool) $watchlist->is_default;
        $this->is_global = (bool) $watchlist->is_global;
    }

    public function save(): void
    {
        $data = $this->validate();

        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if (! $user || ! $user->isAdmin()) {
            $data['is_global'] = false;
        }

        if (! empty($data['is_global']) && $data['is_global']) {
            $data['is_default'] = false;
        }

        if ($this->editingId) {
            $watchlist = Watchlist::findOrFail($this->editingId);
            $this->authorize('update', $watchlist);
            $watchlist->update($data);
        } else {
            $this->authorize('create', Watchlist::class);
            $watchlist = Watchlist::create($data + ['user_id' => auth()->id()]);
        }

        if (! empty($data['is_default']) && $data['is_default'] && ! $watchlist->is_global) {
            $this->applyDefault($watchlist);
        }

        if (! empty($data['is_global']) && $data['is_global']) {
            $this->applyGlobal($watchlist);
        }

        session()->flash('status', __('Watchlist saved.'));
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $watchlist = Watchlist::findOrFail($id);
        $this->authorize('delete', $watchlist);
        $watchlist->delete();

        session()->flash('status', __('Watchlist deleted.'));
    }

    public function setDefault(int $id): void
    {
        $watchlist = Watchlist::findOrFail($id);
        $this->authorize('update', $watchlist);

        if ($watchlist->is_global) {
            return;
        }

        $this->applyDefault($watchlist);

        session()->flash('status', __('Default watchlist updated.'));
    }

    private function applyDefault(Watchlist $watchlist): void
    {
        if ($watchlist->is_global) {
            return;
        }

        DB::transaction(function () use ($watchlist) {
            Watchlist::query()
                ->where('user_id', $watchlist->user_id)
                ->where('is_global', false)
                ->where('id', '!=', $watchlist->id)
                ->update(['is_default' => false]);

            $watchlist->update(['is_default' => true]);
        });
    }

    private function applyGlobal(Watchlist $watchlist): void
    {
        DB::transaction(function () use ($watchlist) {
            if ($watchlist->is_global) {
                Watchlist::query()
                    ->where('id', '!=', $watchlist->id)
                    ->where('is_global', true)
                    ->update(['is_global' => false]);
            }
        });
    }

    public function render()
    {
        $watchlists = Watchlist::query()
            ->where(function ($query) {
                $query->where('user_id', auth()->id())
                    ->orWhere('is_global', true);
            })
            ->orderByDesc('is_global')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('livewire.watchlists.index', [
            'watchlists' => $watchlists,
        ]);
    }
}
