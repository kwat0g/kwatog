<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DssRouteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_dss_route_carries_auth_feature_and_permission_gates(): void
    {
        $dssRoutes = collect(Route::getRoutes())->filter(
            static fn ($route): bool => str_starts_with((string) $route->uri(), 'api/v1/dss')
        );

        $this->assertNotEmpty($dssRoutes, 'No DSS routes registered — ModuleServiceProvider wiring broken.');

        foreach ($dssRoutes as $route) {
            $middleware = collect($route->gatherMiddleware())->map(
                static fn ($middleware): string => is_string($middleware) ? $middleware : (is_object($middleware) ? $middleware::class : '')
            );

            $this->assertContains(
                'auth:sanctum', $middleware,
                "Route {$route->uri()} is missing auth:sanctum."
            );
            $this->assertTrue(
                $middleware->contains(static fn (string $middleware): bool => str_starts_with($middleware, 'feature:')),
                "Route {$route->uri()} is missing the feature gate."
            );
            $this->assertTrue(
                $middleware->contains(static fn (string $middleware): bool => str_starts_with($middleware, 'permission:')),
                "Route {$route->uri()} is missing the permission gate (F-13)."
            );
        }
    }
}
