<div
    wire:poll.2s
    class="p-4 bg-gray-800 rounded-2xl shadow-md text-white max-w-md mx-auto"
>
    <div class="flex justify-between items-center mb-2">
        <span class="font-semibold">Valuation Progress</span>
        <span class="text-sm text-gray-400">{{ $progress }}%</span>
    </div>

    <div class="w-full bg-gray-700 rounded-full h-3 overflow-hidden">
        <div
            class="bg-gradient-to-r from-blue-500 to-cyan-400 h-3 transition-all duration-700 ease-out"
            style="width: {{ $progress }}%"
        ></div>
    </div>

    <div class="mt-2 text-sm text-gray-400">
        {{ number_format($done) }} / {{ number_format($total) }} done
    </div>
    @if ($progress < 100)
        <div class="mt-3 flex items-center text-sm text-blue-300">
            <svg class="animate-spin h-4 w-4 mr-2 text-blue-400" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10"
                        stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor"
                      d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            Calculating valuations...
        </div>
    @endif
</div>
