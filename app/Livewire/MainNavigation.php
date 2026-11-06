<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Auth\AuthorizationResolver;
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
        $resolver = app(AuthorizationResolver::class);
        /** @var User|null $user */
        $user = Auth::user();

        if (! $resolver->canToggleViewMode($user)) {
            abort(403, 'Unauthorized to toggle view mode.');
        }

        $currentMode = $this->getViewMode();
        $targetMode = $mode ? strtolower(trim($mode)) : ($currentMode === 'stocks' ? 'crypto' : 'stocks');

        if (! in_array($targetMode, User::VALID_VIEW_MODES, true)) {
            abort(400, "Invalid view mode [{$mode}]. Must be 'stocks', 'crypto', or 'futures'.");
        }

        $resolver->selectContext($targetMode, $user);

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

    /**
     * Resolve the active view mode based on the authenticated user or anonymous visitor.
     */
    public function getViewMode(): string
    {
        $resolver = app(AuthorizationResolver::class);
        /** @var User|null $user */
        $user = Auth::user();

        return $resolver->resolveActiveContext($user) ?? 'stocks';
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

        $futuresItems = [
            [
                'route' => 'futures.show',
                'parameters' => ['symbol' => 'gcusd'],
                'icon' => 'chart-bar',
                'title' => 'Gold (GCUSD)',
            ],
            [
                'route' => 'futures.show',
                'parameters' => ['symbol' => 'siusd'],
                'icon' => 'chart-bar',
                'title' => 'Silver (SIUSD)',
            ],
            [
                'route' => 'futures.show',
                'parameters' => ['symbol' => 'clusd'],
                'icon' => 'chart-bar',
                'title' => 'Crude Oil (CLUSD)',
            ],
            [
                'route' => 'futures.show',
                'parameters' => ['symbol' => 'ngusd'],
                'icon' => 'chart-bar',
                'title' => 'Natural Gas (NGUSD)',
            ],
            [
                'route' => 'futures.show',
                'parameters' => ['symbol' => 'hgusd'],
                'icon' => 'chart-bar',
                'title' => 'Copper (HGUSD)',
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

        $contextItems = match ($viewMode) {
            'crypto' => $cryptoItems,
            'futures' => $futuresItems,
            default => $stockItems,
        };

        return array_merge([$dashboardItem], $contextItems);
    }

    public function render()
    {
        $resolver = app(AuthorizationResolver::class);
        /** @var User|null $user */
        $user = Auth::user();

        return view('livewire.main-navigation', [
            'menuItems' => $this->getMenuItems(),
            'viewMode' => $this->getViewMode(),
            'canToggleViewMode' => $resolver->canToggleViewMode($user),
            'permittedModes' => $resolver->permittedContexts($user),
            'isGuest' => $user ? $user->isGuest() : false,
        ]);
    }
}
