<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} — {{ config('app.name', 'DuoAsset') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 flex items-center justify-center p-6">
    <div class="w-full max-w-lg bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-xl shadow-sm p-6 space-y-5">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                Email Notification Preferences
            </p>
            <h1 class="mt-1 text-xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $heading }}
            </h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                Signed in as <span class="font-medium">{{ $user->email }}</span>
            </p>
        </div>

        @if (session('status'))
            <div class="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 p-4 text-sm text-green-800 dark:text-green-200">
                {{ session('status') }}
            </div>
        @endif

        <p class="text-sm text-zinc-600 dark:text-zinc-300 leading-relaxed">
            {{ $description }}
        </p>

        <div class="rounded-lg bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-700 p-4 text-sm space-y-2">
            <div class="flex items-center justify-between">
                <span class="font-medium">EPS Surprise Notifications</span>
                <span class="{{ $user->email_eps_surprise_digest ? 'text-green-600 dark:text-green-400 font-semibold' : 'text-zinc-500' }}">
                    {{ $user->email_eps_surprise_digest ? 'Enabled' : 'Disabled' }}
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="font-medium">Setup Scoring Notifications</span>
                <span class="{{ $user->email_setup_scoring_digest ? 'text-green-600 dark:text-green-400 font-semibold' : 'text-zinc-500' }}">
                    {{ $user->email_setup_scoring_digest ? 'Enabled' : 'Disabled' }}
                </span>
            </div>
        </div>

        <form method="POST" action="{{ route('notifications.unsubscribe.perform', ['category' => $category]) }}" class="space-y-3">
            @csrf
            <button
                type="submit"
                class="w-full inline-flex justify-center items-center px-4 py-2.5 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold transition"
            >
                Confirm Unsubscribe
            </button>
        </form>

        <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700 flex items-center justify-between text-xs text-zinc-500">
            <a href="{{ route('settings.profile') }}" class="text-blue-600 dark:text-blue-400 hover:underline">
                Manage all notification preferences in Profile
            </a>
            <a href="{{ route('dashboard') }}" class="hover:underline">
                Return to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
