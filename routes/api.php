<?php

use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\ServerActionController;
use App\Http\Middleware\VerifyInternalTokenMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('api.health');

Route::post('/internal/action-callback', [ServerActionController::class, 'callback'])
    ->middleware(VerifyInternalTokenMiddleware::class)
    ->name('api.internal.action-callback');
