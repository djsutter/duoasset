<div>
    <div class="grid grid-cols-2">
        <x-form-input type="datetime-local" label="Date & time" class="dark:bg-zinc-800" wire:model="form.transaction_at" />
        <div></div>
        <x-form-select label="From" :options="getWalletList()" first="" wire:model.live="form.src_wallet_id" wire:key="src-wallet-{{ $form->src_wallet_id }}" />
        <x-form-select label="To" :options="getWalletList($form->src_currency)" first="" wire:model="form.dst_wallet_id" wire:key="dst-wallet-{{ $form->dst_wallet_id }}" />
        <div class="grid grid-cols-2">
            <x-form-input label="Amount" class="text-right dark:bg-zinc-800" wire:model="form.src_amount" />
            <div class="mt-7 ml-2">{{ $form->src_currency }}</div>
        </div>
        <div class="grid grid-cols-2">
            <x-form-input label="Fee" class="text-right dark:bg-zinc-800" wire:model="form.fee_amount" />
            <x-form-select
                label=""
                :options="getWalletPlatformCurrencyList($form->src_wallet_id)"
                first=""
                wire:model="form.fee_currency"
                wire:key="currency-select-{{ $form->src_wallet_id ?? 'none' }}"
            />
        </div>
    </div>
    <x-form-input label="Description" class="dark:bg-zinc-800" wire:model="form.description" />
    <x-form-input label="Address" class="dark:bg-zinc-800" wire:model="form.address" />
</div>
