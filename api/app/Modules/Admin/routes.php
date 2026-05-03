<?php

declare(strict_types=1);

use App\Modules\Admin\Controllers\PermissionController;
use App\Modules\Admin\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired'])
    ->group(function (): void {

        Route::middleware('permission:admin.roles.manage')->group(function (): void {
            Route::get('roles',                  [RoleController::class, 'index'])->middleware('permission:admin.roles.manage');
            Route::post('roles',                 [RoleController::class, 'store'])->middleware('permission:admin.roles.manage');
            Route::get('roles/{role}',           [RoleController::class, 'show'])->middleware('permission:admin.roles.manage');
            Route::put('roles/{role}',           [RoleController::class, 'update'])->middleware('permission:admin.roles.manage');
            Route::delete('roles/{role}',        [RoleController::class, 'destroy'])->middleware('permission:admin.roles.manage');
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:admin.roles.manage');

            Route::get('permissions/matrix',     [PermissionController::class, 'matrix'])->middleware('permission:admin.roles.manage');
        });

    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired', 'permission:admin.audit_logs.view'])
    ->group(function (): void {
        Route::get('audit-logs',         [\App\Modules\Admin\Controllers\AuditLogController::class, 'index'])
            ->middleware('permission:admin.audit_logs.view');
        Route::get('audit-logs/{id}',    [\App\Modules\Admin\Controllers\AuditLogController::class, 'show'])
            ->middleware('permission:admin.audit_logs.view')
            ->whereNumber('id');
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired', 'permission:admin.settings.manage'])
    ->group(function (): void {
        Route::get('settings',        [\App\Modules\Admin\Controllers\SettingsController::class, 'index'])
            ->middleware('permission:admin.settings.manage');
        Route::put('settings/{key}',  [\App\Modules\Admin\Controllers\SettingsController::class, 'update'])
            ->middleware('permission:admin.settings.manage');
    });

/* Sprint 8 — Task 75. Global search. */
Route::middleware(['auth:sanctum', 'feature:search', 'permission:search.global', 'throttle:30,1'])
    ->get('/search', [\App\Modules\Admin\Controllers\SearchController::class, 'search']);

/* Sprint 8 — Task 76. Bulk approval PDF print. */
Route::middleware(['auth:sanctum', 'permission:admin.print.bulk'])
    ->post('/print/bulk', [\App\Modules\Admin\Controllers\BulkPrintController::class, 'print']);
