<?php

declare(strict_types=1);

use App\Modules\Admin\Controllers\ActivityFeedController;
use App\Modules\Admin\Controllers\PermissionController;
use App\Modules\Admin\Controllers\RoleController;
use App\Modules\Admin\Controllers\UserAdminController;
use App\Modules\Admin\Controllers\UserPermissionOverrideController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired'])
    ->group(function (): void {

        // Series F / Task F7 — Company-wide activity feed.
        Route::get('activity', [ActivityFeedController::class, 'index'])
            ->middleware('permission:admin.activity.view');

        Route::middleware('permission:admin.roles.manage')->group(function (): void {
            Route::get('roles',                  [RoleController::class, 'index'])->middleware('permission:admin.roles.manage');
            Route::post('roles',                 [RoleController::class, 'store'])->middleware('permission:admin.roles.manage');
            Route::get('roles/{role}',           [RoleController::class, 'show'])->middleware('permission:admin.roles.manage');
            Route::put('roles/{role}',           [RoleController::class, 'update'])->middleware('permission:admin.roles.manage');
            Route::delete('roles/{role}',        [RoleController::class, 'destroy'])->middleware('permission:admin.roles.manage');
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:admin.roles.manage');
            // Series R — Task R1: clone an existing role into a new custom role.
            Route::post('roles/{role}/clone',    [RoleController::class, 'clone'])->middleware('permission:admin.roles.manage');

            Route::get('permissions/matrix',     [PermissionController::class, 'matrix'])->middleware('permission:admin.roles.manage');
        });

        // Series R — Task R2: per-user permission overrides.
        Route::middleware('permission:admin.users.manage_permissions')
            ->prefix('users/{user}/overrides')
            ->group(function (): void {
                Route::get('/',           [UserPermissionOverrideController::class, 'index']);
                Route::post('/',          [UserPermissionOverrideController::class, 'store']);
                Route::delete('{override}', [UserPermissionOverrideController::class, 'destroy']);
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

/*
 * Series E (E1/E3) — Document vault HTTP surface.
 * The route group requires *some* baseline document permission so anonymous
 * permission scopes can't probe the vault; per-entity authorization is then
 * enforced inside the controller (delegated to each document type's existing
 * module permissions, e.g. payroll.view, accounting.invoices.view, etc.).
 */
Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired'])
    ->prefix('documents')
    ->group(function (): void {
        Route::get('/',                        [\App\Common\Controllers\DocumentController::class, 'index'])
            ->middleware('permission:admin.audit_logs.view');
        Route::get('{document}',               [\App\Common\Controllers\DocumentController::class, 'show']);
        Route::get('{document}/view',          [\App\Common\Controllers\DocumentController::class, 'view'])
            ->name('documents.view');
        Route::get('{document}/download',      [\App\Common\Controllers\DocumentController::class, 'download'])
            ->name('documents.download');
        Route::delete('{document}',            [\App\Common\Controllers\DocumentController::class, 'destroy'])
            ->middleware('permission:admin.audit_logs.view');
    });

/*
 * Series E (E2) — Export endpoints. Module-specific permissions are enforced
 * inside ExportController::guardModule() because each module requires a
 * different slug (hr.employees.export, payroll.view, inventory.view, ...).
 */
Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired'])
    ->group(function (): void {
        Route::get('/exports/{module}/columns',  [\App\Common\Controllers\ExportController::class, 'columns']);
        Route::put('/exports/{module}/columns',  [\App\Common\Controllers\ExportController::class, 'saveColumns']);
        Route::get('/exports/{module}/preview',  [\App\Common\Controllers\ExportController::class, 'preview']);
        Route::get('/exports/{module}/download', [\App\Common\Controllers\ExportController::class, 'download']);
    });

/*
 * Series E (E2) — Scheduled-export CRUD. Anyone with the view permission can
 * list + create their own; ownership-or-admin enforced inside the controller
 * for show/update/destroy.
 */
Route::middleware([
    'auth:sanctum', 'session.timeout', 'password.expired',
    'permission:admin.scheduled_exports.view',
])
    ->prefix('scheduled-exports')
    ->group(function (): void {
        Route::get('/',                       [\App\Common\Controllers\ScheduledExportController::class, 'index']);
        Route::post('/',                      [\App\Common\Controllers\ScheduledExportController::class, 'store']);
        Route::get('{scheduledExport}',       [\App\Common\Controllers\ScheduledExportController::class, 'show']);
        Route::put('{scheduledExport}',       [\App\Common\Controllers\ScheduledExportController::class, 'update']);
        Route::delete('{scheduledExport}',    [\App\Common\Controllers\ScheduledExportController::class, 'destroy']);
    });
