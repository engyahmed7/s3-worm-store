<?php

use App\Http\Controllers\WormDemoController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:30,1')->group(function () {
    Route::get('/', [WormDemoController::class, 'index'])->name('demo.index');
    Route::post('/write', [WormDemoController::class, 'store'])->name('demo.write');
    Route::get('/download', [WormDemoController::class, 'download'])->name('demo.download');
    Route::post('/delete', [WormDemoController::class, 'destroy'])->name('demo.delete');
});
