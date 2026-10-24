<?php

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserCredit;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Configuration extends Component
{
    public string $tab = 'overview';

    protected $queryString = [
        'tab' => ['except' => 'overview'],
    ];

    public function boot(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Unauthorized. Administrator access required.');
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'accounts'], true)) {
            $this->tab = $tab;
        }
    }

    public function render()
    {
        $totalUsers = User::count();
        $adminCount = User::where('role', UserRole::ADMINISTRATOR)->count();
        $fullSubCount = User::where('role', UserRole::FULL_SUBSCRIPTION)->count();
        $equitiesCount = User::where('role', UserRole::EQUITIES)->count();
        $cryptoCount = User::where('role', UserRole::CRYPTO)->count();
        $guestCount = User::where('role', UserRole::GUEST)->count();

        $totalSubscribers = $fullSubCount + $equitiesCount + $cryptoCount;
        $totalCreditsAllocated = UserCredit::where('amount', '>', 0)->sum('amount');
        $netOutstandingCredits = User::sum('credit_balance');

        return view('livewire.admin.configuration', [
            'totalUsers' => $totalUsers,
            'adminCount' => $adminCount,
            'fullSubCount' => $fullSubCount,
            'equitiesCount' => $equitiesCount,
            'cryptoCount' => $cryptoCount,
            'guestCount' => $guestCount,
            'totalSubscribers' => $totalSubscribers,
            'totalCreditsAllocated' => $totalCreditsAllocated,
            'netOutstandingCredits' => $netOutstandingCredits,
        ])->layout('components.layouts.app', ['title' => 'Admin Configuration - DuoAsset']);
    }
}
