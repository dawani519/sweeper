<?php

use App\Http\Controllers\Api\AddressController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:60,1', 'hmac'])->group(function () {
    Route::post('addresses', [AddressController::class, 'store']);
    Route::get('addresses', [AddressController::class, 'index']);
    Route::get('addresses/{id}', [AddressController::class, 'show'])->whereNumber('id');
});
