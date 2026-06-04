<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogSlowQueries
{
    private const THRESHOLD_MS = 100;

    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->isLocal()) {
            return $next($request);
        }

        DB::listen(function ($query) use ($request) {
            if ($query->time >= self::THRESHOLD_MS) {
                Log::channel('stderr')->warning('Slow query', [
                    'ms'      => $query->time,
                    'sql'     => $query->sql,
                    'method'  => $request->method(),
                    'url'     => $request->path(),
                ]);
            }
        });

        return $next($request);
    }
}
