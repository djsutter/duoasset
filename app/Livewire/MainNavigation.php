<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class MainNavigation extends Component
{
    public $variant = 'header';

    #[On('view-mode-updated')]
    public function refreshNavigation(?string $mode = null): void
    {
        // Re-renders the component when view mode changes
    }

    /**
     * Toggle the active view mode between stocks and crypto, or set to a specific mode.
     */
    public function toggleViewMode(?string $mode = null): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user || ! $user->canToggleViewMode()) {
            abort(403, 'Unauthorized to toggle view mode.');
        }

        $currentMode = $this->getViewMode();
        $targetMode = $mode ? strtolower(trim($mode)) : ($currentMode === 'stocks' ? 'crypto' : 'stocks');

        if (! in_array($targetMode, ['stocks', 'crypto'], true)) {
            abort(400, "Invalid view mode [{$mode}]. Must be 'stocks' or 'crypto'.");
        }

        $user->setViewMode($targetMode);

        $this->dispatch('view-mode-updated', mode: $targetMode);
    }

    /**
     * Explicitly set the view mode to a given perspective.
     */
    public function setViewMode(string $mode): void
    {
        $this->toggleViewMode($mode);
    }

    /**
     * Resolve the active view mode based on the authenticated user.
     */
    public function getViewMode(): string
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            return 'stocks';
        }

        $activeMode = $user->fresh()?->activeViewMode() ?? $user->activeViewMode();

        if ($activeMode === 'crypto' && ! $user->hasCryptoAccess()) {
            return 'stocks';
        }

        if ($activeMode === 'stocks' && ! $user->hasStockAccess()) {
            return 'crypto';
        }

        return $activeMode;
    }

    /**
     * Get menu items matching the active view mode.
     */
    public function getMenuItems(): array
    {
        $viewMode = $this->getViewMode();

        $dashboardItem = [
            'route' => 'dashboard',
            'icon' => 'layout-grid',
            'title' => 'Dashboard',
        ];

        $cryptoItems = [
            [
                'route' => 'import.index',
                'title' => 'Import',
            ],
            [
                'route' => 'import.stage',
                'title' => 'Stage',
            ],
            [
                'route' => 'wallets.index',
                'title' => 'Wallets',
            ],
            [
                'route' => 'currencies.index',
                'title' => 'Currencies',
            ],
            [
                'route' => 'platforms.index',
                'title' => 'Platforms',
            ],
            [
                'route' => 'reports.index',
                'title' => 'Reports',
            ],
        ];

        $stockItems = [
            [
                'route' => 'stocks.index',
                'icon' => 'chart-bar',
                'title' => 'Stocks',
            ],
            [
                'route' => 'money-flows.index',
                'parameters' => [
                    'interval' => 'hourly',
                ],
                'icon' => 'chart-bar',
                'title' => 'Money Flows',
            ],
            [
                'route' => 'watchlists.stock-buy-setups',
                'icon' => 'chart-bar',
                'title' => 'Setups',
            ],
            [
                'route' => 'watchlists.index',
                'icon' => 'eye',
                'title' => 'Watchlists',
            ],
            [
                'route' => 'watchlists.earnings-surprises',
                'icon' => 'chart-bar',
                'title' => 'EPS',
            ],
        ];

        return array_merge(
            [$dashboardItem],
            $viewMode === 'crypto' ? $cryptoItems : $stockItems
        );
    }

    public function render()
    {
        /** @var User|null $user */
        $user = Auth::user();

        return view('livewire.main-navigation', [
            'menuItems' => $this->getMenuItems(),
            'viewMode' => $this->getViewMode(),
            'canToggleViewMode' => $user?->canToggleViewMode() ?? false,
            'isGuest' => $user?->isGuest() ?? false,
        ]);
    }
}
