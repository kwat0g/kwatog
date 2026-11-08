<?php

declare(strict_types=1);

namespace App\Common\Http\Middleware;

use App\Common\Support\E2EEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireE2EControlToken
{
    public function handle(Request $request, Closure $next): Response
    {
        E2EEnvironment::assertEnabled();
        $expected = (string) config('app.e2e.control_token');
        $provided = (string) $request->header('X-E2E-Control', '');

        abort_unless(strlen($provided) === strlen($expected) && hash_equals($expected, $provided), 403);

        return $next($request);
    }
}
