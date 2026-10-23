<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class ViewModeToggle extends Component
{
    public string $viewMode = 'stocks';

    public function mount(): void
    {
        $this->syncViewMode();
    }

    #[On('view-mode-updated')]
    public function syncViewMode(?string $mode = null): void
    {
        if ($mode && in_array(strtolower($mode), ['stocks', 'crypto'], true)) {
            $this->viewMode = strtolower($mode);
            return;
        }

        /** @var User|null $user */
        $user = Auth::user();
        if ($user) {
            $this->viewMode = $user->fresh()?->activeViewMode() ?? $user->activeViewMode();
        }
    }

    /**
     * Toggle the view mode between stocks and crypto, or set to a specific mode.
     */
    public function toggleViewMode(?string $mode = null): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || ! $user->canToggleViewMode()) {
            abort(403, 'Unauthorized to toggle view mode.');
        }

        $currentMode = $this->viewMode;
        $targetMode = $mode ? strtolower(trim($mode)) : ($currentMode === 'stocks' ? 'crypto' : 'stocks');

        if (! in_array($targetMode, ['stocks', 'crypto'], true)) {
            abort(400, "Invalid view mode [{$mode}]. Must be 'stocks' or 'crypto'.");
        }

        $user->setViewMode($targetMode);
        $this->viewMode = $targetMode;

        $this->dispatch('view-mode-updated', mode: $targetMode);
    }

    /**
     * Explicitly set the view mode to a given perspective.
     */
    public function setViewMode(string $mode): void
    {
        $this->toggleViewMode($mode);
    }

    public function render()
    {
        /** @var User|null $user */
        $user = Auth::user();

        return view('livewire.view-mode-toggle', [
            'canToggle' => $user?->canToggleViewMode() ?? false,
            'viewMode' => $this->viewMode,
        ]);
    }
}
