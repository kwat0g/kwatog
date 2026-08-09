<?php

declare(strict_types=1);

use App\Modules\Landing\Controllers\ContactInquiryController;
use App\Modules\Landing\Controllers\ContactInquiryInboxController;
use App\Modules\Landing\Controllers\NewsletterController;
use App\Modules\Landing\Controllers\QualityPolicyController;
use App\Modules\Landing\Controllers\LandingContactController;
use App\Modules\Landing\Controllers\LandingContentController;
use Illuminate\Support\Facades\Route;

// ── Public marketing surface — unauthenticated by design ──────────────────
Route::prefix('landing')->group(function (): void {
    Route::post('contact-inquiry', [ContactInquiryController::class, 'store'])->middleware('throttle:public-form');
    Route::post('newsletter',      [NewsletterController::class, 'store'])->middleware('throttle:public-form');
    // On-demand PDF render is expensive — keep the 10/min public-form limiter
    // (mirrors the comment in bootstrap/app.php). Landing content reads stay
    // unthrottled so normal page navigation is not degraded.
    Route::get('quality-policy', [QualityPolicyController::class, 'download'])->middleware('throttle:public-form');
    Route::get('contact', [LandingContactController::class, 'show']);
    Route::get('content', [LandingContentController::class, 'show']);
});

// ── ERP-side inbox — the consumer the old quote-request path never had ────
Route::middleware(['auth:sanctum'])->prefix('crm')->group(function (): void {
    Route::get('/inquiries/options',               [ContactInquiryInboxController::class, 'options'])     ->middleware('permission:crm.inquiries.view');
    Route::get('/inquiries',                    [ContactInquiryInboxController::class, 'index'])        ->middleware('permission:crm.inquiries.view');
    Route::get('/inquiries/{inquiry}',          [ContactInquiryInboxController::class, 'show'])         ->middleware('permission:crm.inquiries.view');
    Route::patch('/inquiries/{inquiry}/status', [ContactInquiryInboxController::class, 'updateStatus']) ->middleware('permission:crm.inquiries.manage');
});
