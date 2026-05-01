<?php

declare(strict_types=1);

use App\Modules\Admin\Controllers\PermissionController;
use App\Modules\Admin\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired'])
    ->group(function (): void {

        Route::middleware('permission:admin.roles.manage')->group(function (): void {
            Route::get('roles',                  [RoleController::class, 'index']);
            Route::post('roles',                 [RoleController::class, 'store']);
            Route::get('roles/{role}',           [RoleController::class, 'show']);
            Route::put('roles/{role}',           [RoleController::class, 'update']);
            Route::delete('roles/{role}',        [RoleController::class, 'destroy']);
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

            Route::get('permissions/matrix',     [PermissionController::class, 'matrix']);
        });

    });
