<?php

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use App\Models\StockBuySetupRun;
use App\Models\User;
use App\Models\UserCredit;
use App\Notifications\AdminTestNotification;
use App\Services\Admin\LogReaderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class Configuration extends Component
{
    public string $tab = 'overview';

    public ?string $testEmailStatus = null;

    public ?string $testEmailError = null;

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
        if (in_array($tab, ['overview', 'accounts', 'logs', 'setup-run'], true)) {
            $this->tab = $tab;
        }
    }

    public function sendTestEmail(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Unauthorized. Administrator access required.');
        }

        $this->testEmailStatus = null;
        $this->testEmailError = null;

        $sender = config('mail.from.address') ?: 'duoasset@12pro.ca';

        try {
            $user->notify(new AdminTestNotification(now(), $sender));

            Log::info('admin.test_email_sent', [
                'admin_id' => $user->id,
                'email' => $user->email,
                'sender' => $sender,
                'timestamp' => now()->toIso8601String(),
            ]);

            $this->testEmailStatus = "Test verification email successfully dispatched to {$user->email} from {$sender}.";
        } catch (\Throwable $e) {
            Log::error('admin.test_email_failed', [
                'admin_id' => $user->id,
                'email' => $user->email,
                'sender' => $sender,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $this->testEmailError = "Failed to dispatch test email to {$user->email}: {$e->getMessage()}";
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

        $lastSetupRun = StockBuySetupRun::orderByDesc('id')->first();
        /** @var LogReaderService $logService */
        $logService = app(LogReaderService::class);
        $logSummary = $logService->getLogs(null, LogReaderService::TYPE_ALL, LogReaderService::LEVEL_ALL, '', 1)['summary'];

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
            'lastSetupRun' => $lastSetupRun,
            'logSummary' => $logSummary,
        ])->layout('components.layouts.app', ['title' => 'Admin Configuration - DuoAsset']);
    }
}
