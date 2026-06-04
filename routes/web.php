<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('/import', \App\Livewire\Import\Index::class)->name('import.index');
    Route::get('/import/stage', \App\Livewire\Import\Stage::class)->name('import.stage');

    Route::get('/wallets', \App\Livewire\Wallets\Index::class)->name('wallets.index');
    Route::get('/wallets/create', \App\Livewire\Wallets\Edit::class)->name('wallets.create');
    Route::get('/wallets/{wallet}', \App\Livewire\Wallets\Show::class)->name('wallets.show');
    Route::get('/wallets/{wallet}/edit', \App\Livewire\Wallets\Edit::class)->name('wallets.edit');

    Route::get('/currencies', \App\Livewire\Currencies\Index::class)->name('currencies.index');
    Route::get('/platforms', \App\Livewire\Platforms\Index::class)->name('platforms.index');
    Route::get('/platforms/{platform}', \App\Livewire\Platforms\Show::class)->name('platforms.show');

    Route::get('/reports', \App\Livewire\Reports\Index::class)->name('reports.index');
    Route::get('/reports/transactions', \App\Livewire\Reports\Transactions::class)->name('reports.transactions');
    Route::get('/reports/capital-gains', \App\Livewire\Reports\CapitalGains::class)->name('reports.capital-gains');
    Route::get('/reports/acb-audit-ledger', \App\Livewire\Reports\AcbAuditLedger::class)->name('reports.acb-audit-ledger');

    Route::get('invest-events/holdings', \App\Livewire\InvestEvents\Holdings::class)->name('invest-events.holdings');

    Route::get('/transactions/external', \App\Livewire\Transactions\External::class)->name('transactions.external');
    Route::get('/transactions/trades', \App\Livewire\Transactions\Trades::class)->name('transactions.trades');
    Route::get('/transactions/replay', \App\Livewire\Transactions\Replay::class)->name('transactions.replay');

    Route::get('/acb', \App\Livewire\Acb\Index::class)->name('acb.index');
    Route::get('/acb/{asset}', \App\Livewire\Acb\Show::class)->name('acb.show');

    Route::get('/tax/schedule3', \App\Livewire\Tax\Schedule3::class)->name('tax.schedule3');
    Route::get('/tax/schedule3/{year}/{asset}', \App\Livewire\Tax\Schedule3AssetDispositions::class)->name('tax.schedule3.asset');
    Route::get('/tax/schedule3/{year}/{asset}/{acbEvent}', \App\Livewire\Tax\Schedule3DispositionShow::class)->name('tax.schedule3.disposition');
    Route::get('/tax/dispositions-ledger', \App\Livewire\Tax\DispositionsLedger::class)->name('tax.dispositions-ledger');
    Route::get('/tax/pool-ledger', \App\Livewire\Tax\PoolLedger::class)->name('tax.pool-ledger');
    Route::get('/tax/continuity-summary', \App\Livewire\Tax\ContinuitySummary::class)->name('tax.continuity-summary');
});

require __DIR__.'/auth.php';
