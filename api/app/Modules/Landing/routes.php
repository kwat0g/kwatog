<?php

declare(strict_types=1);

use App\Modules\Landing\Controllers\NewsletterController;
use App\Modules\Landing\Controllers\QualityPolicyController;
use App\Modules\Landing\Controllers\QuoteRequestController;
use App\Modules\Landing\Controllers\LandingContactController;
use App\Modules\Landing\Controllers\LandingContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('landing')->group(function (): void {
    Route::post('quote-request', [QuoteRequestController::class, 'store'])->middleware('throttle:public-form');
    Route::post('newsletter',    [NewsletterController::class, 'store'])->middleware('throttle:public-form');
    // On-demand PDF render is expensive — keep the 10/min public-form limiter
    // (mirrors the comment in bootstrap/app.php). Landing content reads stay
    // unthrottled so normal page navigation is not degraded.
    Route::get('quality-policy', [QualityPolicyController::class, 'download'])->middleware('throttle:public-form');
    Route::get('contact', [LandingContactController::class, 'show']);
    Route::get('content', [LandingContentController::class, 'show']);
});
