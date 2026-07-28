<?php

declare(strict_types=1);

use App\Common\Middleware\CheckFeature;
use App\Common\Middleware\CheckPasswordExpiry;
use App\Common\Middleware\CheckPermission;
use App\Common\Middleware\EnsurePortalGuard;
use App\Common\Middleware\ForceJsonResponse;
use App\Common\Middleware\LogSlowQueries;
use App\Common\Middleware\RequestId;
use App\Common\Middleware\SanitizeInput;
use App\Common\Middleware\SessionTimeout;
use App\Providers\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

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
            RequestId::class,
            ForceJsonResponse::class,
            SanitizeInput::class,
        ]);

        // Route-level aliases
        $middleware->alias([
            'permission' => CheckPermission::class,
            'feature' => CheckFeature::class,
            'session.timeout' => SessionTimeout::class,
            'password.expired' => CheckPasswordExpiry::class,
            // B2B portal guard-type assertion — blocks web-session bleed into
            // the sanctum-driver portal guards (see EnsurePortalGuard).
            'portal' => EnsurePortalGuard::class,
            // Sanctum token-ability gate (T2.1 — Edge devices).
            'ability' => CheckAbilities::class,
            'abilities' => CheckForAnyAbility::class,
        ]);

        // Trust the reverse proxy (Nginx) for real client IP
        $middleware->trustProxies(at: '*');

        // Dev-only: log queries that exceed 100ms (no-op in non-local envs)
        $middleware->api(append: [
            // Enforce the security policy consistently. Both middleware inspect
            // the resolved route and no-op for public, portal, and edge routes.
            SessionTimeout::class,
            CheckPasswordExpiry::class,
            ThrottleRequests::class.':api',
            LogSlowQueries::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // JSON envelope for API errors
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->is('sanctum/*') || $request->expectsJson()
        );
    })
    ->booted(function () {
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)
            ->by($r->ip().'|'.$r->input('email', '')));

        RateLimiter::for('api', function (Request $r): Limit {
            $userId = optional($r->user())->getAuthIdentifier();
            if ($userId !== null) {
                return Limit::perMinute(config('rate_limits.api_authenticated_per_minute', 300))
                    ->by('user:'.$userId);
            }

            return Limit::perMinute(config('rate_limits.api_guest_per_minute', 60))
                ->by('ip:'.$r->ip());
        });

        RateLimiter::for('sensitive', fn (Request $r) => Limit::perMinute(10)
            ->by(optional($r->user())->id ?: $r->ip()));

        // Public, unauthenticated landing endpoints (quote request, newsletter,
        // quality-policy PDF). Per-IP cap guards against spam and against DoS via
        // the expensive on-demand PDF render, while staying forgiving for a real
        // visitor who downloads the policy and submits a form in one session.
        RateLimiter::for('public-form', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
    })
    ->create();
