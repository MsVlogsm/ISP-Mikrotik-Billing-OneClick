<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use XLink\SmsBridge\Http\Controllers\SmsBridgeController;

Route::group(['middleware' => ['web', 'auth'], 'prefix' => 'sms-bridge'], function () {
    Route::get('/', [SmsBridgeController::class, 'index'])->name('sms-bridge.index');
    Route::post('/store', [SmsBridgeController::class, 'store'])->name('sms-bridge.store');
    Route::put('/{id}', [SmsBridgeController::class, 'update'])->name('sms-bridge.update');
    Route::put('/{id}/toggle', [SmsBridgeController::class, 'toggle'])->name('sms-bridge.toggle');
    Route::get('/{id}/balance', [SmsBridgeController::class, 'balance'])->name('sms-bridge.balance');
    Route::post('/{id}/test', [SmsBridgeController::class, 'test'])->name('sms-bridge.test');
    Route::delete('/{id}', [SmsBridgeController::class, 'destroy'])->name('sms-bridge.destroy');
});
