<?php

declare(strict_types=1);

use App\Modules\B2B\Middleware\B2BTenancyScopeMiddleware;
use App\Modules\B2B\Middleware\CheckPortalPasswordExpiry;
use App\Modules\ReturnManagement\Controllers\ReturnCaseController;
use Illuminate\Support\Facades\Route;

Route::prefix('return-management/cases')->middleware(['auth:sanctum', 'feature:return_management'])->group(function (): void {
    Route::get('/', [ReturnCaseController::class, 'index'])->middleware('permission:return_management.view');
    Route::get('/sources', [ReturnCaseController::class, 'sources'])->middleware('permission:return_management.view');
    Route::get('/source-options', [ReturnCaseController::class, 'sourceOptions'])->middleware('permission:return_management.view');
    Route::post('/', [ReturnCaseController::class, 'store'])->middleware('permission:return_management.manage');
    Route::get('/{returnCase}', [ReturnCaseController::class, 'show'])->middleware('permission:return_management.view');
    Route::get('/{returnCase}/resolution-options', [ReturnCaseController::class, 'resolutionOptions'])->middleware('permission:return_management.view');
    Route::post('/{returnCase}/actions', [ReturnCaseController::class, 'act'])->middleware('permission_any:return_management.manage,return_management.approve,accounting.credit_notes.manage');
    Route::post('/{returnCase}/attachments', [ReturnCaseController::class, 'attach'])->middleware('permission_any:return_management.manage,return_management.approve,accounting.credit_notes.manage');
    Route::get('/{returnCase}/attachments/{attachment}', [ReturnCaseController::class, 'download'])->middleware('permission:return_management.view');
});

foreach (['customer', 'supplier'] as $realm) {
    Route::prefix('b2b/'.$realm.'/problems')->middleware([
        'auth:'.$realm.'_portal', 'portal:'.$realm.'_portal', 'feature:b2b_portals', 'feature:return_management',
        CheckPortalPasswordExpiry::class, B2BTenancyScopeMiddleware::class, 'portal.password.changed',
    ])->group(function () use ($realm): void {
        Route::get('/', [ReturnCaseController::class, 'index']);
        if ($realm === 'customer') {
            Route::get('/sources', [ReturnCaseController::class, 'sources']);
            Route::get('/source-options', [ReturnCaseController::class, 'sourceOptions']);
            Route::post('/not-arrived/{delivery}', [ReturnCaseController::class, 'reportNotArrived'])->middleware('throttle:sensitive');
            Route::post('/', [ReturnCaseController::class, 'store'])->middleware('throttle:sensitive');
        }
        Route::get('/{returnCase}', [ReturnCaseController::class, 'show']);
        Route::post('/{returnCase}/actions', [ReturnCaseController::class, 'act'])->middleware('throttle:sensitive');
        Route::post('/{returnCase}/attachments', [ReturnCaseController::class, 'attach'])->middleware('throttle:sensitive');
        Route::get('/{returnCase}/attachments/{attachment}', [ReturnCaseController::class, 'download']);
    });
}
