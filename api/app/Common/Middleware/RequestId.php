<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4 — Request correlation.
 *
 * Generates a UUID4 per request (or honours an inbound `X-Request-ID`
 * header from a load balancer), pushes it into the log context so every
 * Log:: call inside the request carries the same id, and echoes it back
 * on the response so the client can quote it when reporting issues.
 */
class RequestId
{
    public const HEADER = 'X-Request-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) $request->headers->get(self::HEADER, '');
        if ($requestId === '' || ! preg_match('/^[A-Za-z0-9._-]{8,128}$/', $requestId)) {
            $requestId = (string) Str::uuid();
        }

        $request->headers->set(self::HEADER, $requestId);
        $request->attributes->set('request_id', $requestId);

        Log::shareContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);
        return $response;
    }
}
