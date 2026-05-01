<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuthUserController;
use App\Modules\Auth\Controllers\ChangePasswordController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\LogoutController;
use App\Modules\Auth\Controllers\PreferencesController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('login', LoginController::class)->middleware('throttle:auth');
    Route::middleware(['auth:sanctum', 'session.timeout'])->group(function (): void {
        Route::post('logout', LogoutController::class);
        Route::post('change-password', ChangePasswordController::class)
            ->middleware('throttle:sensitive');

        // Authenticated reads — also gated by password expiry except change-password itself.
        Route::middleware('password.expired')->group(function (): void {
            Route::get('user', AuthUserController::class);
            Route::patch('user/preferences', [PreferencesController::class, 'update']);
        });
    });
});
