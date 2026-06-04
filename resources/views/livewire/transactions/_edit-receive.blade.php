<div>
    <div class="grid grid-cols-2">
        <x-form-input type="datetime-local" label="Date & time" class="dark:bg-zinc-800" wire:model="form.transaction_at" />
        <x-form-select label="Wallet" :options="getWalletList()" first="" wire:model.live="form.dst_wallet_id" wire:key="dst-wallet-{{ $form->dst_wallet_id }}" />
        <div class="grid grid-cols-2">
            <x-form-input label="Amount" class="text-right dark:bg-zinc-800" wire:model="form.dst_amount" />
            <div class="mt-7 ml-2">{{ $form->dst_currency }}</div>
        </div>
        <div></div>
    </div>
    <x-form-input label="Description" class="dark:bg-zinc-800" wire:model="form.description" />
    <x-form-input label="Address" class="dark:bg-zinc-800" wire:model="form.address" />
</div>
