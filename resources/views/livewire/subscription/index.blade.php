<div class="space-y-8" data-testid="subscription-page">
    {{-- Header --}}
    <div class="text-center max-w-2xl mx-auto">
        <h1 class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-4xl">
            {{ __('Choose Your DuoAsset Membership') }}
        </h1>
        <p class="mt-2 text-base text-zinc-600 dark:text-zinc-400">
            {{ __('Unlock full multi-page setups, proprietary algorithmic indicators, and complete crypto tax continuity.') }}
        </p>
    </div>

    {{-- Session Flash Notifications --}}
    @if (session()->has('message'))
        <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-300 shadow-xs flex items-center justify-between"
             data-testid="subscription-flash-message" role="alert">
            <div class="flex items-center gap-2">
                <flux:icon icon="check-circle" variant="mini" class="size-5 text-emerald-600 dark:text-emerald-400" />
                <span>{{ session('message') }}</span>
            </div>
        </div>
    @endif

    {{-- Current Account & Credit Status Card --}}
    <div class="da-card bg-linear-to-r from-zinc-50 to-zinc-100 dark:from-zinc-900 dark:to-zinc-800/80 border border-zinc-200 dark:border-zinc-700" data-testid="user-membership-status">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <div class="text-xs uppercase font-medium tracking-wider text-zinc-500">{{ __('Current Account Status') }}</div>
                <div class="flex items-center gap-2 mt-1">
                    <span class="text-lg font-bold text-zinc-900 dark:text-white">{{ $user->name }}</span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700" data-testid="current-role-badge">
                        {{ $currentRole->label() }}
                    </span>
                </div>
            </div>

            <div class="sm:text-right">
                <div class="text-xs uppercase font-medium tracking-wider text-zinc-500">{{ __('Available Account Credits') }}</div>
                <div class="font-mono text-2xl font-bold text-emerald-600 dark:text-emerald-400" data-testid="available-credits-amount">
                    ${{ number_format($creditBalance, 2) }}
                </div>
                @if ($creditBalance > 0)
                    <p class="text-xs text-zinc-500 mt-0.5">{{ __('Credits will automatically be applied toward your subscription.') }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Pricing Plans Grid --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- Equities Plan --}}
        <div class="da-card flex flex-col justify-between border-2 {{ $currentRole === \App\Enums\UserRole::EQUITIES ? 'border-sky-500 dark:border-sky-400 ring-2 ring-sky-500/20' : 'border-zinc-200 dark:border-zinc-800' }}" data-testid="plan-card-equities">
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Equities') }}</h3>
                    <span class="inline-flex items-center rounded-md bg-sky-100 px-2 py-0.5 text-xs font-medium text-sky-800 dark:bg-sky-900/50 dark:text-sky-300">
                        {{ __('Stocks Only') }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Full access to stock setups, earnings surprises, and revisions.') }}</p>

                <div class="mt-4 flex items-baseline">
                    <span class="text-3xl font-extrabold text-zinc-900 dark:text-white">$29</span>
                    <span class="ml-1 text-xs text-zinc-500">/ month</span>
                </div>

                <ul class="mt-6 space-y-2.5 text-xs text-zinc-600 dark:text-zinc-300">
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-sky-500" />
                        <span>{{ __('Unrestricted multi-page access (pages 1–3+)') }}</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-sky-500" />
                        <span>{{ __('Stock buy setups & scoring rankings') }}</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-sky-500" />
                        <span>{{ __('Earnings surprises & EPS revision alerts') }}</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-sky-500" />
                        <span>{{ __('Watchlists & hourly money flow charts') }}</span>
                    </li>
                </ul>
            </div>

            <div class="mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-800">
                @if ($currentRole === \App\Enums\UserRole::EQUITIES)
                    <button type="button" disabled class="w-full da-btn bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 cursor-default" data-testid="plan-btn-equities-active">
                        {{ __('Active Plan') }}
                    </button>
                @else
                    <button type="button"
                            wire:click="upgradeToPlan('equities')"
                            class="w-full da-btn da-btn-primary bg-sky-600 hover:bg-sky-700"
                            data-testid="plan-btn-equities">
                        {{ $creditBalance >= 29 ? __('Apply Credit & Activate') : __('Subscribe to Equities') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Full Subscription (Featured) --}}
        <div class="da-card relative flex flex-col justify-between border-2 border-indigo-500 dark:border-indigo-400 shadow-md ring-2 ring-indigo-500/20" data-testid="plan-card-full">
            <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                <span class="inline-flex items-center gap-1 rounded-full bg-indigo-600 px-3 py-0.5 text-xs font-semibold text-white shadow-xs">
                    <flux:icon icon="sparkles" variant="mini" class="size-3.5" />
                    <span>{{ __('Dual Domain • Best Value') }}</span>
                </span>
            </div>

            <div class="pt-2">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Full Subscription') }}</h3>
                    <span class="inline-flex items-center rounded-md bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300">
                        {{ __('Stocks + Crypto') }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Complete access to both Equities and Crypto suites with perspective switching.') }}</p>

                <div class="mt-4 flex items-baseline">
                    <span class="text-3xl font-extrabold text-zinc-900 dark:text-white">$49</span>
                    <span class="ml-1 text-xs text-zinc-500">/ month</span>
                </div>

                <ul class="mt-6 space-y-2.5 text-xs text-zinc-600 dark:text-zinc-300">
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-indigo-500" />
                        <span><strong>{{ __('Complete Equities Suite') }}</strong> (all scoring tools)</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-indigo-500" />
                        <span><strong>{{ __('Complete Crypto Suite') }}</strong> (tax, ACB, wallets)</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-indigo-500" />
                        <span><strong>{{ __('Header & Sidebar Perspective Switcher') }}</strong></span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-indigo-500" />
                        <span>{{ __('Priority support & real-time notification alerts') }}</span>
                    </li>
                </ul>
            </div>

            <div class="mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-800">
                @if ($currentRole === \App\Enums\UserRole::FULL_SUBSCRIPTION)
                    <button type="button" disabled class="w-full da-btn bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 cursor-default" data-testid="plan-btn-full-active">
                        {{ __('Active Plan') }}
                    </button>
                @else
                    <button type="button"
                            wire:click="upgradeToPlan('full_subscription')"
                            class="w-full da-btn da-btn-primary bg-indigo-600 hover:bg-indigo-700"
                            data-testid="plan-btn-full">
                        {{ $creditBalance >= 49 ? __('Apply Credit & Activate') : __('Subscribe to Full Access') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Crypto Plan --}}
        <div class="da-card flex flex-col justify-between border-2 {{ $currentRole === \App\Enums\UserRole::CRYPTO ? 'border-emerald-500 dark:border-emerald-400 ring-2 ring-emerald-500/20' : 'border-zinc-200 dark:border-zinc-800' }}" data-testid="plan-card-crypto">
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Crypto') }}</h3>
                    <span class="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300">
                        {{ __('Crypto Only') }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Complete tracking for multi-chain wallets, ACB ledgers, and CRA taxes.') }}</p>

                <div class="mt-4 flex items-baseline">
                    <span class="text-3xl font-extrabold text-zinc-900 dark:text-white">$29</span>
                    <span class="ml-1 text-xs text-zinc-500">/ month</span>
                </div>

                <ul class="mt-6 space-y-2.5 text-xs text-zinc-600 dark:text-zinc-300">
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-emerald-500" />
                        <span>{{ __('Unlimited wallet address & platform tracking') }}</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-emerald-500" />
                        <span>{{ __('Automated ACB audit ledgers & daily reconciliations') }}</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-emerald-500" />
                        <span>{{ __('CRA Schedule 3 capital gains tax reports') }}</span>
                    </li>
                    <li class="flex items-center gap-2">
                        <flux:icon icon="check" variant="mini" class="size-4 text-emerald-500" />
                        <span>{{ __('Superficial loss & tax pool continuity tracking') }}</span>
                    </li>
                </ul>
            </div>

            <div class="mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-800">
                @if ($currentRole === \App\Enums\UserRole::CRYPTO)
                    <button type="button" disabled class="w-full da-btn bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 cursor-default" data-testid="plan-btn-crypto-active">
                        {{ __('Active Plan') }}
                    </button>
                @else
                    <button type="button"
                            wire:click="upgradeToPlan('crypto')"
                            class="w-full da-btn da-btn-primary bg-emerald-600 hover:bg-emerald-700"
                            data-testid="plan-btn-crypto">
                        {{ $creditBalance >= 29 ? __('Apply Credit & Activate') : __('Subscribe to Crypto') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
