<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Auth\AuthorizationResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class ViewModeToggle extends Component
{
    public string $viewMode = 'stocks';

    public bool $canToggle = false;

    /** @var list<string> */
    public array $permittedModes = ['stocks'];

    public function mount(): void
    {
        $this->syncViewMode();
    }

    #[On('view-mode-updated')]
    public function syncViewMode(?string $mode = null): void
    {
        $resolver = app(AuthorizationResolver::class);
        /** @var User|null $user */
        $user = Auth::user();

        $this->canToggle = $resolver->canToggleViewMode($user);
        $this->permittedModes = $resolver->permittedContexts($user);

        if ($mode && in_array(strtolower($mode), $this->permittedModes, true)) {
            $this->viewMode = strtolower($mode);

            return;
        }

        $this->viewMode = $resolver->resolveActiveContext($user) ?? 'stocks';
    }

    /**
     * Toggle the view mode between stocks and crypto, or set to a specific mode.
     */
    public function toggleViewMode(?string $mode = null): void
    {
        $resolver = app(AuthorizationResolver::class);
        /** @var User|null $user */
        $user = Auth::user();

        if (! $resolver->canToggleViewMode($user)) {
            abort(403, 'Unauthorized to toggle view mode.');
        }

        $currentMode = $resolver->resolveActiveContext($user) ?? 'stocks';
        $targetMode = $mode ? strtolower(trim($mode)) : ($currentMode === 'stocks' ? 'crypto' : 'stocks');

        if (! in_array($targetMode, User::VALID_VIEW_MODES, true)) {
            abort(400, "Invalid view mode [{$mode}]. Must be 'stocks', 'crypto', or 'futures'.");
        }

        $this->viewMode = $resolver->selectContext($targetMode, $user);
        $this->permittedModes = $resolver->permittedContexts($user);
        $this->canToggle = $resolver->canToggleViewMode($user);

        $this->dispatch('view-mode-updated', mode: $targetMode);

        $referer = (string) request()->headers->get('referer', '');
        if ($referer !== '') {
            $path = '/' . ltrim((string) parse_url($referer, PHP_URL_PATH), '/');
            if ($path !== '/' && $path !== '/dashboard' && ! str_starts_with($path, '/livewire')) {
                $this->redirect(route('dashboard'), navigate: false);
            }
        }
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
        $this->syncViewMode();

        return view('livewire.view-mode-toggle', [
            'canToggle' => $this->canToggle,
            'viewMode' => $this->viewMode,
            'permittedModes' => $this->permittedModes,
        ]);
    }
}
