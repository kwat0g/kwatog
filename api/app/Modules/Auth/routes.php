<?php

declare(strict_types=1);

use App\Modules\Auth\Controllers\AuthUserController;
use App\Modules\Auth\Controllers\ChangePasswordController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\LogoutController;
use App\Modules\Auth\Controllers\NotificationController;
use App\Modules\Auth\Controllers\PreferencesController;
use App\Modules\Auth\Controllers\UserInviteController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    // Public — login attempt + CSRF priming.
    Route::post('login', LoginController::class)->middleware('throttle:auth');

    // WS-A.1 — Public accept of an invite token (no auth, throttled).
    // Placed BEFORE the auth:sanctum group so the accept endpoint stays
    // reachable for users who do not yet have a session.
    Route::post('invites/accept', [UserInviteController::class, 'accept'])
        ->middleware('throttle:auth');

    // WS-A.1 — Authenticated invite management (HR / system_admin).
    Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired',
                       'permission:auth.users.invite'])
        ->group(function (): void {
            Route::get('invites',              [UserInviteController::class, 'index']);
            Route::post('invites',             [UserInviteController::class, 'store']);
            Route::delete('invites/{invite}',  [UserInviteController::class, 'destroy']);
        });

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

/* Sprint 8 — Task 77. Notifications + per-user channel preferences. */
Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired'])->prefix('notifications')->group(function () {
    Route::get('/',                  [NotificationController::class, 'index'])
        ->middleware('permission:notifications.view');
    Route::patch('/{id}/read',       [NotificationController::class, 'markRead'])
        ->middleware('permission:notifications.view');
    Route::patch('/read-all',        [NotificationController::class, 'markAllRead'])
        ->middleware('permission:notifications.view');
});

Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired',
                   'permission:notifications.preferences.manage'])
    ->prefix('notification-preferences')->group(function () {
        Route::get('/',  [NotificationController::class, 'preferencesIndex'])
            ->middleware('permission:notifications.preferences.manage');
        Route::put('/',  [NotificationController::class, 'preferencesUpdate'])
            ->middleware('permission:notifications.preferences.manage');
    });
