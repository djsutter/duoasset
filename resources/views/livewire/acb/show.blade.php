<div class="p-4">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold">{{ $asset->asset_code }} — ACB Detail</h1>
        <div class="text-sm text-gray-600">
            Last transaction: {{ optional($asset->last_transaction_at)->format('Y-m-d') ?? '-' }}
        </div>
    </div>

    <div class="mb-8">
        <x-tab :selected="$detail == 'daily'"><a href="#daily" wire:click="$set('detail', 'daily')">Daily ACB</a></x-tab>
        <x-tab :selected="$detail == 'events'"><a href="#events" wire:click="$set('detail', 'events')">Transaction Events</a></x-tab>
        <x-tab :selected="$detail == 'disposals'"><a href="#disposals" wire:click="$set('detail', 'disposals')">Capital Gains/Disposals</a></x-tab>
    </div>

    <livewire:acb.detail :asset="$asset" :detail="$detail" key="detail-{{ $detail }}"></livewire:acb.detail>
</div>
