<div>
    <h1>Wallets</h1>

    <div class="grid grid-cols-3 gap-4">
        <div class="border-r border-r-gray-200 dark:border-r-gray-600">
            @include('livewire.wallets._wallet-list', ['title' => 'Exchanges', 'items' => $exchanges])
        </div>
        <div class="border-r pl-12 border-r-gray-200 dark:border-r-gray-600">
            @include('livewire.wallets._wallet-list', ['title' => 'Wallet Apps', 'items' => $softwareWallets])
        </div>
        <div class="pl-12">
            @include('livewire.wallets._wallet-list', ['title' => 'External Wallets', 'items' => $externalWallets])
        </div>
    </div>
</div>
