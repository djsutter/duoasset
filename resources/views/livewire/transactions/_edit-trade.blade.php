<div>
    <div class="grid grid-cols-2">
        <x-form-input type="datetime-local" label="Date & time" class="dark:bg-zinc-800" wire:model="form.transaction_at" />
        <x-form-select label="Platform" :options="getExchangeList()" first="" wire:model.live="form.platform_id" />
        <div class="grid grid-cols-2">
            <x-form-input label="Sell" class="text-right dark:bg-zinc-800" wire:model="form.src_amount" />
            <x-form-select label="" :options="getAvailableCurrencyList($form->platform_id)" first="" wire:model="form.src_currency" wire:key="src-currency-{{ $form->platform_id ?? 'none' }}" />
        </div>
        <div class="grid grid-cols-2">
            <x-form-input label="Buy" class="text-right dark:bg-zinc-800" wire:model="form.dst_amount" />
            <x-form-select label="" :options="getAvailableCurrencyList($form->platform_id)" first="" wire:model="form.dst_currency" wire:key="dst-currency-{{ $form->platform_id ?? 'none' }}" />
        </div>
        <div class="grid grid-cols-2">
            <x-form-input label="Fee" class="text-right dark:bg-zinc-800" wire:model="form.fee_amount" />
            <x-form-select label="" :options="getAvailableCurrencyList($form->platform_id)" first="" wire:model="form.fee_currency" wire:key="fee-currency-{{ $form->platform_id ?? 'none' }}" />
        </div>
    </div>
    <x-form-input label="Description" class="dark:bg-zinc-800" wire:model="form.description" />
</div>
