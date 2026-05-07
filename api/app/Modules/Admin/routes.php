<?php

declare(strict_types=1);

use App\Modules\Admin\Controllers\PermissionController;
use App\Modules\Admin\Controllers\RoleController;
use App\Modules\Admin\Controllers\UserAdminController;
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

        // U2 — central user-management surface.
        Route::prefix('users')
            ->middleware('permission:admin.users.manage')
            ->group(function (): void {
                Route::get('/',                       [UserAdminController::class, 'index']);
                Route::post('/',                      [UserAdminController::class, 'store']);
                Route::get('{user}',                  [UserAdminController::class, 'show']);
                Route::patch('{user}/unlock',         [UserAdminController::class, 'unlock']);
                Route::patch('{user}/deactivate',     [UserAdminController::class, 'deactivate']);
                Route::patch('{user}/activate',       [UserAdminController::class, 'activate']);
                Route::patch('{user}/role',           [UserAdminController::class, 'changeRole']);
                Route::patch('{user}/reset-password', [UserAdminController::class, 'resetPassword']);
                Route::get('{user}/login-history',    [UserAdminController::class, 'loginHistory']);
            });
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired', 'permission:admin.audit_logs.view'])
    ->group(function (): void {
        Route::get('audit-logs',         [\App\Modules\Admin\Controllers\AuditLogController::class, 'index'])
            ->middleware('permission:admin.audit_logs.view');
        // Sprint P7 — CSV export. Same filter set as `index`. Must come
        // before the `{id}` route to keep `export` from being matched as
        // an id segment.
        Route::get('audit-logs/export',  [\App\Modules\Admin\Controllers\AuditLogController::class, 'export'])
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
