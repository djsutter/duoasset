<?php

use App\Http\Controllers\Api\MarketDataApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/market-data')->middleware(['api.market_data', 'throttle:market-data-api'])->group(function () {
    Route::get('/earnings/calendar', [MarketDataApiController::class, 'earningsCalendar']);
    Route::get('/earnings/surprises', [MarketDataApiController::class, 'earningsSurprises']);
    Route::get('/{symbol}/candles', [MarketDataApiController::class, 'candles'])->where('symbol', '[A-Za-z0-9_.\-]+');
    Route::get('/{symbol}/profile', [MarketDataApiController::class, 'profile'])->where('symbol', '[A-Za-z0-9_.\-]+');
    Route::get('/{symbol}/quote', [MarketDataApiController::class, 'quote'])->where('symbol', '[A-Za-z0-9_.\-]+');
    Route::get('/{symbol}/financials', [MarketDataApiController::class, 'financials'])->where('symbol', '[A-Za-z0-9_.\-]+');
});
