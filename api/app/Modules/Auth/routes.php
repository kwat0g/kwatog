<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuthUserController;
use App\Modules\Auth\Controllers\ChangePasswordController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\LogoutController;
use App\Modules\Auth\Controllers\PreferencesController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    // Public — login attempt + CSRF priming.
    Route::post('login', LoginController::class)->middleware('throttle:auth');

    // Authenticated. NOTE: GET /auth/user is intentionally OUTSIDE the
    // `password.expired` gate — the SPA must be able to bootstrap (and the
    // change-password screen must know who the current user is) even when
    // their password has expired. The expiry gate is enforced on every
    // OTHER authenticated route via the global module middleware.
    Route::middleware(['auth:sanctum', 'session.timeout'])->group(function (): void {
        Route::post('logout', LogoutController::class);
        Route::post('change-password', ChangePasswordController::class)
            ->middleware('throttle:sensitive');

        Route::get('user', AuthUserController::class);

        // Mutating preference update is gated by password expiry — a user
        // with an expired password should change it before tweaking prefs.
        Route::patch('user/preferences', [PreferencesController::class, 'update'])
            ->middleware('password.expired');
    });
});
