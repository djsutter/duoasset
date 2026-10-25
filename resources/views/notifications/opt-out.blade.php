<x-layouts.auth.simple title="Notification Preferences">
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Notification Preferences
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Manage your outbound email alert subscriptions
            </p>
        </div>

        @if (session('status'))
            <div class="rounded-md bg-emerald-500/10 p-3 text-center text-sm font-medium text-emerald-600 dark:text-emerald-400">
                {{ session('status') }}
            </div>
        @endif

        @if (! $valid)
            <div class="rounded-md bg-rose-500/10 p-4 text-center text-sm text-rose-600 dark:text-rose-400">
                <p class="font-medium">{{ $error ?? 'Invalid or expired opt-out link.' }}</p>
                <p class="mt-2 text-xs">
                    Please make sure you clicked the entire link from your email message.
                </p>
            </div>
        @else
            <div class="rounded-lg border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="space-y-2 text-sm">
                    <div>
                        <span class="text-xs uppercase tracking-wider text-zinc-500">Recipient</span>
                        <p class="font-semibold text-zinc-900 dark:text-white">{{ $optOut->email }}</p>
                    </div>

                    <div>
                        <span class="text-xs uppercase tracking-wider text-zinc-500">Notification Type</span>
                        <p class="font-medium text-zinc-900 dark:text-white">{{ $optOut->typeLabel() }}</p>
                    </div>

                    <div class="pt-2">
                        <span class="text-xs uppercase tracking-wider text-zinc-500">Current Status</span>
                        @if ($isOptedOut)
                            <p class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-rose-500/10 px-2.5 py-0.5 text-xs font-medium text-rose-600 dark:text-rose-400">
                                <span>●</span> Unsubscribed
                            </p>
                        @else
                            <p class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                <span>●</span> Active / Subscribed
                            </p>
                        @endif
                    </div>
                </div>

                <form method="POST" action="{{ route('notifications.opt-out.update') }}" class="mt-6 flex flex-col gap-3">
                    @csrf
                    <input type="hidden" name="email" value="{{ $optOut->email }}">
                    <input type="hidden" name="type" value="{{ $optOut->item_type }}">
                    <input type="hidden" name="key" value="{{ $optOut->token }}">

                    @if ($isOptedOut)
                        <input type="hidden" name="action" value="opt_in">
                        <flux:button variant="primary" type="submit" class="w-full">
                            Resubscribe to this notification
                        </flux:button>
                    @else
                        <input type="hidden" name="action" value="opt_out">
                        <flux:button variant="danger" type="submit" class="w-full">
                            Unsubscribe from this notification
                        </flux:button>
                    @endif
                </form>
            </div>
        @endif

        <div class="text-center text-xs text-zinc-500 dark:text-zinc-400">
            <a href="{{ route('home') }}" class="underline hover:text-zinc-800 dark:hover:text-zinc-200">
                Return to DuoAsset
            </a>
        </div>
    </div>
</x-layouts.auth.simple>
