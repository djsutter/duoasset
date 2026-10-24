<?php

namespace App\Livewire\Admin;

use App\Models\StockBuySetupRun;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SetupRunReport extends Component
{
    public ?int $selectedRunId = null;

    protected $queryString = [
        'selectedRunId' => ['except' => null, 'as' => 'run'],
    ];

    public function boot(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Unauthorized. Administrator access required.');
        }
    }

    public function selectRun(int $id): void
    {
        $this->selectedRunId = $id;
    }

    public function render()
    {
        $recentRuns = StockBuySetupRun::orderByDesc('id')->limit(25)->get();

        $activeRun = null;
        if ($this->selectedRunId !== null) {
            $activeRun = StockBuySetupRun::find($this->selectedRunId);
        }

        if (! $activeRun) {
            $activeRun = $recentRuns->first();
        }

        $breakdown = $activeRun ? $activeRun->rejectionBreakdown() : [];

        return view('livewire.admin.setup-run-report', [
            'run' => $activeRun,
            'recentRuns' => $recentRuns,
            'breakdown' => $breakdown,
            'categoryLabels' => StockBuySetupRun::categoryLabels(),
        ])->layout('components.layouts.app', ['title' => 'Stock Buy Setup Run Report - DuoAsset']);
    }
}
