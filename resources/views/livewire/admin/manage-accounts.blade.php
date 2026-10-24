<div class="space-y-6">
    {{-- Header with Navigation and Actions --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">{{ __('Manage Accounts') }}</h1>
                <span class="inline-flex items-center rounded-md bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-800 dark:bg-purple-900/50 dark:text-purple-300">
                    {{ __('Admin Portal') }}
                </span>
            </div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                {{ __('Create accounts, assign or remove roles, and allocate dollar credits.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.config') }}"
               class="da-btn da-btn-secondary gap-1.5"
               wire:navigate
               data-testid="back-to-config-btn">
                <flux:icon icon="wrench-screwdriver" variant="mini" class="size-4" />
                <span>{{ __('Admin Configuration') }}</span>
            </a>

            <button type="button"
                    wire:click="openCreateModal"
                    class="da-btn da-btn-primary gap-1.5"
                    data-testid="open-create-account-btn">
                <flux:icon icon="plus" variant="mini" class="size-4" />
                <span>{{ __('Create Account') }}</span>
            </button>
        </div>
    </div>

    {{-- Session Flash Notifications --}}
    @if (session()->has('message'))
        <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-300 shadow-xs flex items-center justify-between"
             data-testid="admin-flash-message" role="alert">
            <div class="flex items-center gap-2">
                <flux:icon icon="check-circle" variant="mini" class="size-5 text-emerald-600 dark:text-emerald-400" />
                <span>{{ session('message') }}</span>
            </div>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/60 dark:bg-red-950/40 dark:text-red-300 shadow-xs flex items-center justify-between"
             data-testid="admin-flash-error" role="alert">
            <div class="flex items-center gap-2">
                <flux:icon icon="exclamation-circle" variant="mini" class="size-5 text-red-600 dark:text-red-400" />
                <span>{{ session('error') }}</span>
            </div>
        </div>
    @endif

    {{-- Filter and Search Toolbar --}}
    <div class="da-card">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
            <div class="md:col-span-3">
                <label class="da-label">{{ __('Search Accounts') }}</label>
                <div class="relative">
                    <input type="search"
                           wire:model.live.debounce.300ms="search"
                           placeholder="{{ __('Search by user name or email address...') }}"
                           class="da-input"
                           data-testid="accounts-search-input" />
                </div>
            </div>

            <div>
                <label class="da-label">{{ __('Role Filter') }}</label>
                <select wire:model.live="filterRole" class="da-input" data-testid="accounts-role-filter">
                    <option value="">{{ __('All Roles') }}</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Accounts Table --}}
    <div class="da-card overflow-x-auto p-0">
        <table class="da-table" data-testid="accounts-table">
            <thead>
                <tr>
                    <th class="w-16">ID</th>
                    <th>{{ __('User') }}</th>
                    <th>{{ __('Role') }}</th>
                    <th>{{ __('View Mode') }}</th>
                    <th class="text-right">{{ __('Dollar Credit') }}</th>
                    <th>{{ __('Registered') }}</th>
                    <th class="text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr wire:key="user-row-{{ $user->id }}" data-testid="user-row-{{ $user->id }}">
                        <td class="font-mono text-xs text-zinc-500">#{{ $user->id }}</td>
                        <td>
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $user->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $user->email }}</div>
                        </td>
                        <td>
                            @php
                                $roleBadge = match ($user->role) {
                                    \App\Enums\UserRole::ADMINISTRATOR => 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300 border-purple-300 dark:border-purple-700',
                                    \App\Enums\UserRole::FULL_SUBSCRIPTION => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300 border-indigo-300 dark:border-indigo-700',
                                    \App\Enums\UserRole::EQUITIES => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300 border-sky-300 dark:border-sky-700',
                                    \App\Enums\UserRole::CRYPTO => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 border-emerald-300 dark:border-emerald-700',
                                    default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 border-zinc-200 dark:border-zinc-700',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border {{ $roleBadge }}"
                                  data-testid="user-role-badge-{{ $user->id }}">
                                {{ $user->role->label() }}
                            </span>
                        </td>
                        <td>
                            <span class="inline-flex items-center gap-1 text-xs text-zinc-600 dark:text-zinc-400 capitalize font-mono">
                                <flux:icon icon="{{ $user->activeViewMode() === 'crypto' ? 'currency-dollar' : 'chart-bar' }}" variant="mini" class="size-3.5" />
                                <span>{{ $user->activeViewMode() }}</span>
                            </span>
                        </td>
                        <td class="text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400"
                            data-testid="user-credit-balance-{{ $user->id }}">
                            {{ $user->formattedCreditBalance() }}
                        </td>
                        <td class="text-xs text-zinc-500">
                            {{ $user->created_at?->format('M d, Y') ?? '—' }}
                        </td>
                        <td class="text-right whitespace-nowrap">
                            <div class="inline-flex items-center justify-end gap-1">
                                {{-- Assign Role Button --}}
                                <button type="button"
                                        wire:click="openRoleModal({{ $user->id }})"
                                        class="px-2 py-1 text-xs font-medium rounded text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-950/50 transition"
                                        title="{{ __('Assign or Change Role') }}"
                                        data-testid="btn-assign-role-{{ $user->id }}">
                                    {{ __('Role') }}
                                </button>

                                {{-- Remove Role Button (if not guest) --}}
                                @if (! $user->isGuest())
                                    <button type="button"
                                            wire:click="removeRole({{ $user->id }})"
                                            wire:confirm="{{ __('Are you sure you want to remove this role and reset the account to Guest?') }}"
                                            class="px-2 py-1 text-xs font-medium rounded text-amber-600 hover:text-amber-800 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-950/50 transition"
                                            title="{{ __('Remove Role (Reset to Guest)') }}"
                                            data-testid="btn-remove-role-{{ $user->id }}">
                                        {{ __('Remove Role') }}
                                    </button>
                                @endif

                                {{-- Apply Credit Button --}}
                                <button type="button"
                                        wire:click="openCreditModal({{ $user->id }})"
                                        class="px-2 py-1 text-xs font-medium rounded text-emerald-600 hover:text-emerald-800 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/50 transition"
                                        title="{{ __('Apply Dollar Credit') }}"
                                        data-testid="btn-apply-credit-{{ $user->id }}">
                                    {{ __('+ Credit') }}
                                </button>

                                {{-- Credit History Button --}}
                                <button type="button"
                                        wire:click="openHistoryModal({{ $user->id }})"
                                        class="px-2 py-1 text-xs font-medium rounded text-zinc-500 hover:text-zinc-800 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 transition"
                                        title="{{ __('View Credit Transaction Ledger') }}"
                                        data-testid="btn-history-{{ $user->id }}">
                                    {{ __('History') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                            {{ __('No accounts found matching your query.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    <div>
        {{ $users->links() }}
    </div>

    {{-- CREATE ACCOUNT MODAL --}}
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" data-testid="create-account-modal">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm" wire:click="closeCreateModal"></div>

                <div class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800">
                    <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Create New User Account') }}</h3>
                        <button type="button" wire:click="closeCreateModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                            <flux:icon icon="x-mark" variant="mini" class="size-5" />
                        </button>
                    </div>

                    <form wire:submit="createAccount" class="mt-4 space-y-4">
                        <div>
                            <label class="da-label">{{ __('Full Name') }}</label>
                            <input type="text" wire:model="new_name" class="da-input" placeholder="e.g. John Doe" data-testid="new-name-input" />
                            @error('new_name') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="da-label">{{ __('Email Address') }}</label>
                            <input type="email" wire:model="new_email" class="da-input" placeholder="user@example.com" data-testid="new-email-input" />
                            @error('new_email') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="da-label">{{ __('Initial Password') }}</label>
                            <input type="password" wire:model="new_password" class="da-input" placeholder="Minimum 8 characters" data-testid="new-password-input" />
                            @error('new_password') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="da-label">{{ __('Account Role') }}</label>
                            <select wire:model="new_role" class="da-input" data-testid="new-role-select">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                                @endforeach
                            </select>
                            @error('new_role') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="da-label">{{ __('Initial Dollar Credit ($)') }}</label>
                            <input type="number" step="0.01" min="0" wire:model="new_initial_credit" class="da-input" placeholder="0.00" data-testid="new-credit-input" />
                            <p class="text-xs text-zinc-500 mt-1">{{ __('Optional dollar credit balance granted on creation.') }}</p>
                            @error('new_initial_credit') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                            <button type="button" wire:click="closeCreateModal" class="da-btn da-btn-secondary">
                                {{ __('Cancel') }}
                            </button>
                            <button type="submit" class="da-btn da-btn-primary" data-testid="submit-create-account">
                                {{ __('Create Account') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ASSIGN / CHANGE ROLE MODAL --}}
    @if ($showRoleModal && $selectedUserForRole)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" data-testid="assign-role-modal">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm" wire:click="closeRoleModal"></div>

                <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800">
                    <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Assign / Change Role') }}</h3>
                        <button type="button" wire:click="closeRoleModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                            <flux:icon icon="x-mark" variant="mini" class="size-5" />
                        </button>
                    </div>

                    <div class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Updating role for:') }} <strong class="text-zinc-900 dark:text-white">{{ $selectedUserForRole->name }}</strong> ({{ $selectedUserForRole->email }})
                    </div>

                    <form wire:submit="updateRole" class="mt-4 space-y-4">
                        <div>
                            <label class="da-label">{{ __('Select Role') }}</label>
                            <select wire:model="selectedRole" class="da-input" data-testid="assign-role-select">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->value }}">{{ $role->label() }}</option>
                                @endforeach
                            </select>
                            @error('selectedRole') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                            <button type="button" wire:click="closeRoleModal" class="da-btn da-btn-secondary">
                                {{ __('Cancel') }}
                            </button>
                            <button type="submit" class="da-btn da-btn-primary" data-testid="submit-update-role">
                                {{ __('Save Role') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- APPLY DOLLAR CREDIT MODAL --}}
    @if ($showCreditModal && $selectedUserForCredit)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" data-testid="apply-credit-modal">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm" wire:click="closeCreditModal"></div>

                <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800">
                    <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Apply Dollar Credit') }}</h3>
                        <button type="button" wire:click="closeCreditModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                            <flux:icon icon="x-mark" variant="mini" class="size-5" />
                        </button>
                    </div>

                    <div class="mt-3 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 text-sm">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">{{ __('Target Account:') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ $selectedUserForCredit->name }}</span>
                        </div>
                        <div class="flex justify-between mt-1">
                            <span class="text-zinc-500">{{ __('Current Balance:') }}</span>
                            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400">{{ $selectedUserForCredit->formattedCreditBalance() }}</span>
                        </div>
                    </div>

                    <form wire:submit="applyCredit" class="mt-4 space-y-4">
                        <div>
                            <label class="da-label">{{ __('Action Type') }}</label>
                            <select wire:model="creditAction" class="da-input" data-testid="credit-action-select">
                                <option value="add">{{ __('Add Credit (+)') }}</option>
                                <option value="deduct">{{ __('Deduct Credit (-)') }}</option>
                            </select>
                            @error('creditAction') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="da-label">{{ __('Amount ($ USD)') }}</label>
                            <input type="number" step="0.01" min="0.01" wire:model="creditAmount" class="da-input" placeholder="25.00" data-testid="credit-amount-input" />
                            @error('creditAmount') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="da-label">{{ __('Description / Reference (Optional)') }}</label>
                            <input type="text" wire:model="creditDescription" class="da-input" placeholder="e.g. Promotional grant, loyalty credit" data-testid="credit-description-input" />
                            @error('creditDescription') <p class="da-error">{{ $message }}</p> @enderror
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-3 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                            <button type="button" wire:click="closeCreditModal" class="da-btn da-btn-secondary">
                                {{ __('Cancel') }}
                            </button>
                            <button type="submit" class="da-btn da-btn-primary" data-testid="submit-apply-credit">
                                {{ __('Apply Credit Adjustment') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- CREDIT TRANSACTION HISTORY MODAL --}}
    @if ($showHistoryModal && $selectedUserForHistory)
        <div class="fixed inset-0 z-50 overflow-y-auto" role="dialog" aria-modal="true" data-testid="credit-history-modal">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-zinc-950/60 backdrop-blur-sm" wire:click="closeHistoryModal"></div>

                <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl bg-white p-6 shadow-2xl ring-1 ring-zinc-200 dark:bg-zinc-900 dark:ring-zinc-800">
                    <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                        <div>
                            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Credit History Ledger') }}</h3>
                            <p class="text-xs text-zinc-500">{{ $selectedUserForHistory->name }} ({{ $selectedUserForHistory->email }}) — Current: <strong class="text-emerald-600">{{ $selectedUserForHistory->formattedCreditBalance() }}</strong></p>
                        </div>
                        <button type="button" wire:click="closeHistoryModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                            <flux:icon icon="x-mark" variant="mini" class="size-5" />
                        </button>
                    </div>

                    <div class="mt-4 max-h-96 overflow-y-auto">
                        <table class="da-table">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th class="text-right">{{ __('Amount') }}</th>
                                    <th class="text-right">{{ __('Balance After') }}</th>
                                    <th>{{ __('Admin') }}</th>
                                    <th>{{ __('Description') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($selectedUserForHistory->credits as $credit)
                                    <tr>
                                        <td class="text-xs text-zinc-500 whitespace-nowrap">{{ $credit->created_at->format('Y-m-d H:i') }}</td>
                                        <td class="text-xs uppercase font-mono">{{ $credit->type }}</td>
                                        <td class="text-right font-mono font-semibold {{ (float)$credit->amount >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $credit->formattedAmount() }}
                                        </td>
                                        <td class="text-right font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                            {{ $credit->formattedBalanceAfter() }}
                                        </td>
                                        <td class="text-xs text-zinc-500">{{ $credit->admin?->name ?? 'System' }}</td>
                                        <td class="text-xs text-zinc-600 dark:text-zinc-300">{{ $credit->description ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-6 text-zinc-500">{{ __('No credit transactions recorded for this account.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 flex justify-end pt-3 border-t border-zinc-200 dark:border-zinc-800">
                        <button type="button" wire:click="closeHistoryModal" class="da-btn da-btn-secondary">
                            {{ __('Close') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
