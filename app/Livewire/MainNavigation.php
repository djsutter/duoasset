<?php

namespace App\Livewire;

use Livewire\Component;

class MainNavigation extends Component
{
    public $variant = 'header';

    public function render()
    {
        $menuItems = [
            [
                'route' => 'dashboard',
                'icon' => 'layout-grid',
                'title' => 'Dashboard',
            ],
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
            [
                'route' => 'stocks.index',
                'icon' => 'chart-bar',
                'title' => 'Stocks',
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

        return view('livewire.main-navigation', [
            'menuItems' => $menuItems,
        ]);
    }
}
