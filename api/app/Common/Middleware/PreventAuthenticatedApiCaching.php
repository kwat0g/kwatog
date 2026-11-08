<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Prevent browser and intermediary storage of authenticated API representations. */
final class PreventAuthenticatedApiCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return self::requiresAuthentication($request)
            ? self::apply($response)
            : $response;
    }

    /** Keep CORS and other middleware's existing cache-variation dimensions. */
    public static function apply(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        self::appendVaryHeaders($response, ['Cookie', 'Authorization']);

        return $response;
    }

    public static function requiresAuthentication(Request $request): bool
    {
        $routeMiddleware = $request->route()?->gatherMiddleware() ?? [];

        return $request->user() !== null
            || collect($routeMiddleware)->contains(
                static fn (string $middleware): bool => str_starts_with($middleware, 'auth:')
                    || $middleware === 'auth',
            );
    }

    /** @param list<string> $headers */
    private static function appendVaryHeaders(Response $response, array $headers): void
    {
        $existing = $response->headers->get('Vary', '');
        $values = array_values(array_filter(array_map('trim', explode(',', $existing))));

        if (in_array('*', $values, true)) {
            return;
        }

        $byName = [];
        foreach ([...$values, ...$headers] as $value) {
            $normalized = strtolower($value);
            $byName[$normalized] ??= $value;
        }

        $response->headers->set('Vary', implode(', ', array_values($byName)));
    }
}
