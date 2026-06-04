<div>
    <h2 class="font-bold mb-8">Import CSV Files</h2>

    <h3>Files to import:</h3>

    <ul class="mt-2 mb-6 ml-4 text-xs">
        @foreach ($filesToImport as $file)
            <li>{{ $file }}</li>
        @endforeach
    </ul>

    <div class="grid grid-cols-2">
        <div class="text-left">
            <flux:button variant="primary" class="bg-blue-600 hover:bg-blue-800 text-white" wire:click="import">{{ __('Click to Import!') }}</flux:button>
        </div>
    </div>
</div>
