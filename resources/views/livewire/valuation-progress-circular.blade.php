<div
    wire:poll.2s
    class="flex flex-col items-center justify-center bg-gray-800 rounded-2xl shadow-md text-white p-6 max-w-xs mx-auto"
>
    @php
        $color = $progress < 50 ? 'from-red-500 to-yellow-400'
            : ($progress < 100 ? 'from-blue-500 to-cyan-400' : 'from-green-500 to-emerald-400');
    @endphp
    <div class="relative">
        {{-- The circular progress ring --}}
        <svg class="w-32 h-32 transform -rotate-90" viewBox="0 0 100 100">
            {{-- Background ring --}}
            <circle
                cx="50"
                cy="50"
                r="45"
                stroke="rgba(255,255,255,0.1)"
                stroke-width="10"
                fill="none"
            ></circle>

            {{-- Foreground progress ring --}}
            <circle
                cx="50"
                cy="50"
                r="45"
                stroke="url(#gradient)"
                stroke-width="10"
                stroke-linecap="round"
                fill="none"
                stroke-dasharray="282.6"
                stroke-dashoffset="{{ 282.6 - (282.6 * $progress / 100) }}"
                class="transition-all duration-700 ease-out"
            ></circle>

            {{-- Gradient definition --}}
            <defs>
                <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    @if ($progress < 50)
                        <stop offset="0%" stop-color="#ef4444" />
                        <stop offset="100%" stop-color="#facc15" />
                    @elseif ($progress < 100)
                        <stop offset="0%" stop-color="#3b82f6" />
                        <stop offset="100%" stop-color="#06b6d4" />
                    @else
                        <stop offset="0%" stop-color="#10b981" />
                        <stop offset="100%" stop-color="#34d399" />
                    @endif
                </linearGradient>
            </defs>
        </svg>

        {{-- Center text --}}
        <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-2xl font-bold">{{ $progress }}%</span>
        </div>
    </div>

    <div class="mt-4 text-sm text-gray-400">
        {{ number_format($done) }} / {{ number_format($total) }} done
    </div>

    @if ($progress > 1 && $progress < 100)
        <div class="mt-2 flex items-center text-sm text-blue-300">
            <svg class="animate-spin h-4 w-4 mr-2 text-blue-400" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10"
                        stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                      d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            Calculating valuations...
        </div>
    @elseif ($progress > 1)
        <div class="mt-2 text-green-400 text-sm">All valuations complete</div>
    @endif
</div>
