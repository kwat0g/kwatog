<?php

declare(strict_types=1);

use App\Modules\Landing\Controllers\NewsletterController;
use App\Modules\Landing\Controllers\QualityPolicyController;
use App\Modules\Landing\Controllers\QuoteRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('landing')->group(function (): void {
    Route::post('quote-request', [QuoteRequestController::class, 'store'])->middleware('throttle:public-form');
    Route::post('newsletter',    [NewsletterController::class, 'store'])->middleware('throttle:public-form');
    Route::get('quality-policy', [QualityPolicyController::class, 'download'])->middleware('throttle:public-form');
});
