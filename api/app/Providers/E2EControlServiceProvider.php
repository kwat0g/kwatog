<?php

declare(strict_types=1);

namespace App\Providers;

use App\Common\Http\Middleware\ApplyE2EClock;
use App\Common\Http\Middleware\RequireE2EControlToken;
use App\Common\Support\E2EClock;
use App\Common\Support\E2EEnvironment;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class E2EControlServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('app.e2e.enabled')) {
            return;
        }

        E2EEnvironment::assertEnabled();
        app(E2EClock::class)->apply();

        $router = $this->app->make('router');
        $router->pushMiddlewareToGroup('api', ApplyE2EClock::class);
        $router->pushMiddlewareToGroup('web', ApplyE2EClock::class);

        Queue::looping(static fn () => app(E2EClock::class)->apply());

        Route::middleware(['api', RequireE2EControlToken::class])
            ->prefix('api/v1/_e2e/clock')
            ->group(function (): void {
                Route::get('/', [\App\Modules\Admin\Controllers\E2EClockController::class, 'show']);
                Route::post('/', [\App\Modules\Admin\Controllers\E2EClockController::class, 'freeze']);
                Route::delete('/', [\App\Modules\Admin\Controllers\E2EClockController::class, 'clear']);
            });
    }
}
