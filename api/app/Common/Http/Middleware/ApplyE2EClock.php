<?php

declare(strict_types=1);

namespace App\Common\Http\Middleware;

use App\Common\Support\E2EClock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplyE2EClock
{
    public function handle(Request $request, Closure $next): Response
    {
        app(E2EClock::class)->apply();

        return $next($request);
    }
}
