<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LodisSettingController;
use App\Http\Controllers\MonitoredDomainController;
use App\Http\Controllers\ServerActionController;
use App\Http\Middleware\IpWhitelistMiddleware;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified', IpWhitelistMiddleware::class])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/metrics', [DashboardController::class, 'metrics'])->name('dashboard.metrics');

    Route::get('domains', [MonitoredDomainController::class, 'index'])->name('domains.index');
    Route::post('domains', [MonitoredDomainController::class, 'store'])->name('domains.store');
    Route::post('domains/scan-all', [MonitoredDomainController::class, 'scanAll'])->name('domains.scan-all');
    Route::put('domains/{domain}', [MonitoredDomainController::class, 'update'])->name('domains.update');
    Route::delete('domains/{domain}', [MonitoredDomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('domains/{domain}/scan', [MonitoredDomainController::class, 'scan'])->name('domains.scan');

    Route::get('logs', [ServerActionController::class, 'index'])->name('logs.index');
    Route::post('server-actions/trigger', [ServerActionController::class, 'trigger'])->name('server-actions.trigger');
    Route::get('server-actions/{log}/status', [ServerActionController::class, 'status'])->name('server-actions.status');

    Route::get('lodis-settings', [LodisSettingController::class, 'index'])->name('lodis-settings.index');
    Route::put('lodis-settings', [LodisSettingController::class, 'update'])->name('lodis-settings.update');
    Route::post('lodis-settings/test-notification', [LodisSettingController::class, 'testNotification'])->name('lodis-settings.test-notification');
});

require __DIR__.'/settings.php';
