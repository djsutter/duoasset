<?php

namespace App\Livewire\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class ManageAccounts extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filterRole = '';

    // Create Account Modal state
    public bool $showCreateModal = false;
    public string $new_name = '';
    public string $new_email = '';
    public string $new_password = '';
    public string $new_role = 'guest';
    public string $new_initial_credit = '';

    // Role Edit Modal state
    public bool $showRoleModal = false;
    public ?int $selectedUserId = null;
    public string $selectedRole = 'guest';

    // Credit Modal state
    public bool $showCreditModal = false;
    public ?int $creditUserId = null;
    public string $creditAmount = '';
    public string $creditAction = 'add'; // 'add' or 'deduct'
    public string $creditDescription = '';

    // Credit History Modal state
    public bool $showHistoryModal = false;
    public ?int $historyUserId = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'filterRole' => ['except' => ''],
    ];

    public function boot(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Unauthorized. Administrator access required.');
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilterRole(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->resetErrorBag();
        $this->new_name = '';
        $this->new_email = '';
        $this->new_password = '';
        $this->new_role = 'guest';
        $this->new_initial_credit = '';
        $this->showCreateModal = true;
    }

    public function closeCreateModal(): void
    {
        $this->showCreateModal = false;
    }

    public function createAccount(): void
    {
        $this->validate([
            'new_name' => ['required', 'string', 'max:255'],
            'new_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'new_password' => ['required', 'string', 'min:8'],
            'new_role' => ['required', Rule::enum(UserRole::class)],
            'new_initial_credit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $roleEnum = UserRole::from($this->new_role);
        $viewMode = match ($roleEnum) {
            UserRole::CRYPTO => 'crypto',
            default => 'stocks',
        };

        $user = User::create([
            'name' => $this->new_name,
            'email' => $this->new_email,
            'password' => Hash::make($this->new_password),
            'role' => $roleEnum,
            'view_mode' => $viewMode,
            'credit_balance' => 0.00,
        ]);

        $initialCredit = (float) $this->new_initial_credit;
        if ($initialCredit > 0) {
            $user->applyCredit($initialCredit, 'Initial account credit', Auth::user(), 'initial');
        }

        session()->flash('message', "Account for '{$user->name}' was successfully created with role {$roleEnum->label()}.");
        $this->closeCreateModal();
    }

    public function openRoleModal(int $userId): void
    {
        $this->resetErrorBag();
        $user = User::findOrFail($userId);
        $this->selectedUserId = $user->id;
        $this->selectedRole = $user->role->value;
        $this->showRoleModal = true;
    }

    public function closeRoleModal(): void
    {
        $this->showRoleModal = false;
        $this->selectedUserId = null;
    }

    public function updateRole(): void
    {
        $this->validate([
            'selectedRole' => ['required', Rule::enum(UserRole::class)],
        ]);

        $user = User::findOrFail($this->selectedUserId);

        if ($user->id === Auth::id() && $this->selectedRole !== UserRole::ADMINISTRATOR->value) {
            $this->addError('selectedRole', 'You cannot remove administrator privileges from your own active account.');
            return;
        }

        $user->assignRole($this->selectedRole);

        session()->flash('message', "Role for '{$user->name}' was updated to {$user->role->label()}.");
        $this->closeRoleModal();
    }

    public function removeRole(int $userId): void
    {
        $user = User::findOrFail($userId);

        if ($user->id === Auth::id()) {
            session()->flash('error', 'You cannot remove administrator privileges from your own active account.');
            return;
        }

        $user->removeRole();
        session()->flash('message', "Role removed for '{$user->name}' (reset to Guest).");
    }

    public function openCreditModal(int $userId): void
    {
        $this->resetErrorBag();
        $this->creditUserId = $userId;
        $this->creditAmount = '';
        $this->creditAction = 'add';
        $this->creditDescription = '';
        $this->showCreditModal = true;
    }

    public function closeCreditModal(): void
    {
        $this->showCreditModal = false;
        $this->creditUserId = null;
    }

    public function applyCredit(): void
    {
        $this->validate([
            'creditAmount' => ['required', 'numeric', 'gt:0'],
            'creditAction' => ['required', 'in:add,deduct'],
            'creditDescription' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::findOrFail($this->creditUserId);
        $amount = (float) $this->creditAmount;
        $signedAmount = $this->creditAction === 'deduct' ? -1 * $amount : $amount;
        $type = $this->creditAction === 'deduct' ? 'debit' : 'credit';

        $description = $this->creditDescription ?: ($this->creditAction === 'deduct' ? 'Manual credit deduction' : 'Manual credit grant');

        $credit = $user->applyCredit($signedAmount, $description, Auth::user(), $type);

        session()->flash('message', "Applied {$credit->formattedAmount()} credit to {$user->name}. New balance: {$user->formattedCreditBalance()}.");
        $this->closeCreditModal();
    }

    public function openHistoryModal(int $userId): void
    {
        $this->historyUserId = $userId;
        $this->showHistoryModal = true;
    }

    public function closeHistoryModal(): void
    {
        $this->showHistoryModal = false;
        $this->historyUserId = null;
    }

    public function render()
    {
        $query = User::query();

        if (trim($this->search) !== '') {
            $term = '%' . trim($this->search) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                  ->orWhere('email', 'like', $term);
            });
        }

        if ($this->filterRole !== '') {
            $query->where('role', $this->filterRole);
        }

        $users = $query->orderBy('id', 'asc')->paginate(15);

        $selectedUserForRole = $this->selectedUserId ? User::find($this->selectedUserId) : null;
        $selectedUserForCredit = $this->creditUserId ? User::find($this->creditUserId) : null;
        $selectedUserForHistory = $this->historyUserId ? User::with('credits.admin')->find($this->historyUserId) : null;

        return view('livewire.admin.manage-accounts', [
            'users' => $users,
            'roles' => UserRole::cases(),
            'selectedUserForRole' => $selectedUserForRole,
            'selectedUserForCredit' => $selectedUserForCredit,
            'selectedUserForHistory' => $selectedUserForHistory,
        ])->layout('components.layouts.app', ['title' => 'Manage Accounts - Admin']);
    }
}
