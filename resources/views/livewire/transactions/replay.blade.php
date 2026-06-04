<div class="space-y-4">

    <h2 class="text-xl font-bold">Replay Join Log</h2>

    {{-- Upload form --}}
    <div class="p-4 border rounded bg-gray-50 dark:bg-gray-800">
        <form wire:submit.prevent="runReplay" class="space-y-3">

            <div>
                <input
                    type="file"
                    wire:model="logFile"
                    accept="application/json"
                    class="block w-full"
                >

                @error('logFile')
                <div class="text-red-600 text-sm mt-1">{{ $message }}</div>
                @enderror
            </div>

            <flux:button
                type="submit"
                variant="primary"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-800 text-white rounded"
            >
                Replay
            </flux:button>
        </form>
    </div>

    @if ($results)
        <div class="p-4 border rounded bg-gray-100 dark:bg-gray-800">
            <h3 class="font-semibold mb-2">Replay Results:</h3>

            <pre class="text-sm whitespace-pre-wrap">
{{ json_encode($results, JSON_PRETTY_PRINT) }}
            </pre>
        </div>
    @endif
</div>
