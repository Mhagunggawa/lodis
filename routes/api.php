<?php

use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\ServerActionController;
use App\Http\Middleware\VerifyInternalTokenMiddleware;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('api.health');

Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->name('api.telegram.webhook');

Route::middleware(VerifyInternalTokenMiddleware::class)->group(function () {
    Route::get('/internal/pending-actions', [ServerActionController::class, 'pending'])
        ->name('api.internal.pending-actions');

    Route::post('/internal/action-callback', [ServerActionController::class, 'callback'])
        ->name('api.internal.action-callback');
});
