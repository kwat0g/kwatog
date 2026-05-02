<?php

declare(strict_types=1);

use App\Common\Middleware\CheckFeature;
use App\Common\Middleware\CheckPasswordExpiry;
use App\Common\Middleware\CheckPermission;
use App\Common\Middleware\ForceJsonResponse;
use App\Common\Middleware\SanitizeInput;
use App\Common\Middleware\SessionTimeout;
use App\Providers\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withProviders([
        ModuleServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Stateful SPA cookie auth (NEVER bearer tokens). This injects
        // EnsureFrontendRequestsAreStateful into the API middleware group.
        $middleware->statefulApi();

        // Global API middleware additions — every API request gets these.
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            SanitizeInput::class,
        ]);

        // Route-level aliases
        $middleware->alias([
            'permission'        => CheckPermission::class,
            'feature'           => CheckFeature::class,
            'session.timeout'   => SessionTimeout::class,
            'password.expired'  => CheckPasswordExpiry::class,
        ]);

        // Trust the reverse proxy (Nginx) for real client IP
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // JSON envelope for API errors
        $exceptions->shouldRenderJsonWhen(fn (Request $request) =>
            $request->is('api/*') || $request->is('sanctum/*') || $request->expectsJson()
        );
    })
    ->booted(function () {
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)
            ->by($r->ip().'|'.$r->input('email', '')));

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(60)
            ->by(optional($r->user())->id ?: $r->ip()));

        RateLimiter::for('sensitive', fn (Request $r) => Limit::perMinute(10)
            ->by(optional($r->user())->id ?: $r->ip()));
    })
    ->create();
