<?php

use App\Models\Currency;
use App\Models\Platform;
use App\Models\Wallet;
use Carbon\Carbon;

/**
 * Use in functions where you accept Carbon|string|null args.
 */
function carbonize(Carbon|string|null $input): ?Carbon
{
    return is_string($input) ? Carbon::parse($input) : $input;
}

function getAvailableCurrencyList(?int $platformId): array
{
    if (! $platformId) {
        return [];
    }

    $platform = Platform::find($platformId);

    return $platform->wallets
        ->pluck('currency', 'currency') // key => value
        ->sortKeys()
        ->toArray();
}

function getCurrencyList(): array
{
    return [
        'Fiat' => Currency::fiat()->orderBy('name')->pluck('currency_code', 'currency_code')->toArray(),
        'Crypto' => Currency::crypto()->orderBy('name')->pluck('currency_code', 'currency_code')->toArray(),
    ];
}

function getExchangeList(): array
{
    return Platform::where('can_trade', 1)->orderBy('name')->pluck('name', 'id')->toArray();
}

function getReportingCurrency()
{
    return 'CAD';
}

/**
 * Get a list of wallets based on providers, for a specific currency.
 */
function getWalletProviderList(?string $currency): array
{
    $walletProviderList = [];
    if ($currency) {
        foreach (Wallet::where('currency', $currency)->get() as $wallet) {
            $platform = $wallet->platform;
            $name = $platform->name.($wallet->name == $currency ? '' : ' - '.$wallet->name);
            if ($platform->type == 'software' || $platform->type == 'hardware') {
                $walletProviderList['Self-custody'][$wallet->id] = $name;
            } elseif ($platform->type == 'exchange') {
                $walletProviderList['Exchanges'][$wallet->id] = $name;
            }
        }
    }

    return $walletProviderList;
}

function getPlatformList(): array
{
    $providerList = [];

    foreach (Platform::orderBy('name')->get() as $platform) {
        if ($platform->type == 'software' || $platform->type == 'hardware') {
            $providerList['Self-custody'][$platform->id] = $platform->name;
        } elseif ($platform->type == 'exchange') {
            $providerList['Exchanges'][$platform->id] = $platform->name;
        }
    }

    return $providerList;
}

function getWalletPlatformCurrencyList(?int $walletId): array
{
    $currencyList = [];

    if ($walletId && $wallet = Wallet::with('platform')->find($walletId)) {
        if ($wallet->platform) {
            foreach ($wallet->platform->wallets as $wallet) {
                $currencyList[$wallet->currency] = $wallet->currency;
            }
        }
    }

    return $currencyList;
}

function getWalletList(?string $currency = null): array
{
    $walletList = [];

    foreach (Platform::orderBy('name')->get() as $platform) {
        $query = Wallet::where('platform_id', $platform->id);
        if ($currency) {
            $query->where('currency', $currency);
        }
        foreach ($query->orderBy('name')->get() as $wallet) {
            $walletList[$wallet->id] = $platform->name.' - '.$wallet->name;
        }
    }

    return $walletList;
}
