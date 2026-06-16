<?php

namespace App\Livewire\Watchlists;

use App\Models\Watchlist;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Index extends Component
{
    public ?int $editingId = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public ?string $description = null;

    #[Validate('nullable|string|max:255')]
    public ?string $sector_label = null;

    public bool $is_default = false;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sector_label' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
        ];
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = null;
        $this->sector_label = null;
        $this->is_default = false;
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
        $this->is_default = $watchlist->is_default;
    }

    public function save(): void
    {
        $data = $this->validate();

        if ($this->editingId) {
            $watchlist = Watchlist::findOrFail($this->editingId);
            $this->authorize('update', $watchlist);
            $watchlist->update($data);
        } else {
            $this->authorize('create', Watchlist::class);
            $watchlist = Watchlist::create($data + ['user_id' => auth()->id()]);
        }

        if (! empty($data['is_default']) && $data['is_default']) {
            $this->applyDefault($watchlist);
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

        $this->applyDefault($watchlist);

        session()->flash('status', __('Default watchlist updated.'));
    }

    private function applyDefault(Watchlist $watchlist): void
    {
        DB::transaction(function () use ($watchlist) {
            Watchlist::query()
                ->where('user_id', $watchlist->user_id)
                ->where('id', '!=', $watchlist->id)
                ->update(['is_default' => false]);

            $watchlist->update(['is_default' => true]);
        });
    }

    public function render()
    {
        $watchlists = Watchlist::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('livewire.watchlists.index', [
            'watchlists' => $watchlists,
        ]);
    }
}
