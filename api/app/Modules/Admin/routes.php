<?php

declare(strict_types=1);

use App\Common\Controllers\DocumentController;
use App\Common\Controllers\ExportController;
use App\Common\Controllers\ImportController;
use App\Common\Controllers\ScheduledExportController;
use App\Modules\Admin\Controllers\ActivityFeedController;
use App\Modules\Admin\Controllers\ApprovalDelegationController;
use App\Modules\Admin\Controllers\AuditLogController;
use App\Modules\Admin\Controllers\BulkPrintController;
use App\Modules\Admin\Controllers\PermissionController;
use App\Modules\Admin\Controllers\RoleController;
use App\Modules\Admin\Controllers\SearchController;
use App\Modules\Admin\Controllers\SessionController;
use App\Modules\Admin\Controllers\SettingsController;
use App\Modules\Admin\Controllers\SodController;
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
            Route::get('roles', [RoleController::class, 'index'])->middleware('permission:admin.roles.manage');
            Route::post('roles', [RoleController::class, 'store'])->middleware('permission:admin.roles.manage');
            // ADV4 — side-by-side permission diff. MUST be declared before the
            // `roles/{role}` binding or the literal `compare` segment would be
            // captured as a hash_id and 404.
            Route::get('roles/compare', [RoleController::class, 'compare']);
            Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:admin.roles.manage');
            Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:admin.roles.manage');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:admin.roles.manage');
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->middleware('permission:admin.roles.manage');
            // Series R — Task R1: clone an existing role into a new custom role.
            Route::post('roles/{role}/clone', [RoleController::class, 'clone'])->middleware('permission:admin.roles.manage');

            Route::get('permissions/matrix', [PermissionController::class, 'matrix'])->middleware('permission:admin.roles.manage');
        });

        // Series R — Task R2: per-user permission overrides.
        Route::middleware('permission:admin.users.manage_permissions')
            ->prefix('users/{user}/overrides')
            ->group(function (): void {
                Route::get('/', [UserPermissionOverrideController::class, 'index']);
                Route::post('/', [UserPermissionOverrideController::class, 'store']);
                Route::delete('{override}', [UserPermissionOverrideController::class, 'destroy']);
            });

        // U2 — central user-management surface.
        Route::prefix('users')
            ->middleware('permission:admin.users.manage')
            ->group(function (): void {
                Route::get('/', [UserAdminController::class, 'index']);
                Route::post('/', [UserAdminController::class, 'store']);
                // ADV — bulk role update. MUST be declared before `{user}` wildcard routes
                // or the literal string `bulk-role` gets captured as a hash ID and 404s.
                Route::patch('bulk-role', [UserAdminController::class, 'bulkChangeRole']);
                Route::get('{user}', [UserAdminController::class, 'show']);
                Route::patch('{user}/unlock', [UserAdminController::class, 'unlock']);
                Route::patch('{user}/deactivate', [UserAdminController::class, 'deactivate']);
                Route::patch('{user}/activate', [UserAdminController::class, 'activate']);
                Route::patch('{user}/role', [UserAdminController::class, 'changeRole']);
                Route::patch('{user}/reset-password', [UserAdminController::class, 'resetPassword']);
                Route::get('{user}/login-history', [UserAdminController::class, 'loginHistory']);
            });
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired', 'permission:admin.audit_logs.view'])
    ->group(function (): void {
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:admin.audit_logs.view');
        // Entity-scoped trail — "show all changes to PO-202604-0015".
        // Must come before `{id}` to avoid segment capture.
        Route::get('audit-logs/entity', [AuditLogController::class, 'entityTrail'])
            ->middleware('permission:admin.audit_logs.view');
        // Sprint P7 — CSV export. Same filter set as `index`. Must come
        // before the `{id}` route to keep `export` from being matched as
        // an id segment.
        Route::get('audit-logs/export', [AuditLogController::class, 'export'])
            ->middleware('permission:admin.audit_logs.view');
        // PDF export — same filter set as index, rendered as landscape PDF.
        Route::get('audit-logs/export/pdf', [AuditLogController::class, 'exportPdf'])
            ->middleware('permission:admin.audit_logs.view');
        Route::get('audit-logs/{id}', [AuditLogController::class, 'show'])
            ->middleware('permission:admin.audit_logs.view');

        // REC-01 — Segregation-of-Duties matrix + "who violates SoD today" report.
        Route::get('sod/matrix', [SodController::class, 'index'])
            ->middleware('permission:admin.sod.view');
        Route::get('sod/violations', [SodController::class, 'violations'])
            ->middleware('permission:admin.sod.view');
    });

Route::prefix('admin')
    ->middleware(['auth:sanctum', 'session.timeout', 'password.expired', 'permission:admin.settings.manage'])
    ->group(function (): void {
        Route::get('settings', [SettingsController::class, 'index'])
            ->middleware('permission:admin.settings.manage');
        Route::put('settings/{key}', [SettingsController::class, 'update'])
            ->middleware('permission:admin.settings.manage');
        Route::get('system-info', [SettingsController::class, 'systemInfo'])
            ->middleware('permission:admin.settings.manage');
        Route::get('sessions', [SessionController::class, 'index'])
            ->middleware('permission:admin.settings.manage');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])
            ->middleware('permission:admin.settings.manage');
    });

/* Sprint 8 — Task 75. Global search. */
Route::middleware(['auth:sanctum', 'feature:search', 'permission:search.global', 'throttle:30,1'])
    ->get('/search', [SearchController::class, 'search']);

/* Sprint 8 — Task 76. Bulk approval PDF print. */
Route::middleware(['auth:sanctum', 'permission:admin.print.bulk'])
    ->post('/print/bulk', [BulkPrintController::class, 'print']);

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
        Route::get('/', [DocumentController::class, 'index'])
            ->middleware('permission:admin.audit_logs.view');
        // NOTE: show/view/download are deliberately NOT gated by a blanket
        // permission — DocumentController::authorizeAccess() enforces per-document
        // access (confidentiality + ownership) so non-admin users can fetch their
        // own documents (e.g. payslips). index/destroy remain admin-only below.
        Route::get('{document}', [DocumentController::class, 'show']);
        Route::get('{document}/view', [DocumentController::class, 'view'])
            ->name('documents.view');
        Route::get('{document}/download', [DocumentController::class, 'download'])
            ->name('documents.download');
        Route::delete('{document}', [DocumentController::class, 'destroy'])
            ->middleware('permission:admin.audit_logs.view');
    });

/*
 * Series E (E2) — Export endpoints. Module-specific permissions are enforced
 * inside ExportController::guardModule() because each module requires a
 * different slug (hr.employees.export, payroll.view, inventory.view, ...).
 */
Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired'])
    ->group(function (): void {
        Route::get('/exports/{module}/columns', [ExportController::class, 'columns']);
        Route::put('/exports/{module}/columns', [ExportController::class, 'saveColumns']);
        Route::get('/exports/{module}/preview', [ExportController::class, 'preview']);
        Route::get('/exports/{module}/download', [ExportController::class, 'download']);
    });

/*
 * REC-03 — master-data CSV import (go-live cutover). Gated by a single
 * migration-capability permission; the entity's own module data is created by
 * the importer, but only trusted migration staff hold this slug.
 */
Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired', 'permission:admin.import.manage'])
    ->prefix('imports')
    ->group(function (): void {
        Route::get('/', [ImportController::class, 'entities']);
        Route::get('/batches', [ImportController::class, 'index']);
        Route::post('/{entity}/dry-run', [ImportController::class, 'dryRun']);
        Route::post('/{entity}/commit', [ImportController::class, 'commit']);
        Route::post('/batches/{batch}/rollback', [ImportController::class, 'rollback']);
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
        Route::get('/', [ScheduledExportController::class, 'index']);
        Route::post('/', [ScheduledExportController::class, 'store']);
        Route::get('{scheduledExport}', [ScheduledExportController::class, 'show']);
        Route::put('{scheduledExport}', [ScheduledExportController::class, 'update']);
        Route::delete('{scheduledExport}', [ScheduledExportController::class, 'destroy']);
    });

/*
 * OGAMI-013 — Approval delegation (self-service "who covers for me").
 * Any authenticated user manages delegations where they are the delegator;
 * system_admin manages anyone's. Per-row ownership is enforced inside
 * ApprovalDelegationService (delegator pinning + revoke ownership check), so
 * no blanket permission slug is required beyond authentication — mirroring the
 * scheduled-exports / documents self-service pattern above.
 */
Route::middleware(['auth:sanctum', 'session.timeout', 'password.expired'])
    ->prefix('approval-delegations')
    ->group(function (): void {
        Route::get('/', [ApprovalDelegationController::class, 'index']);
        Route::post('/', [ApprovalDelegationController::class, 'store']);
        Route::delete('{delegation}', [ApprovalDelegationController::class, 'destroy']);
    });
