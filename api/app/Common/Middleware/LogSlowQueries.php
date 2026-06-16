<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 5b — Slow-query telemetry. Logs any query whose execution time
 * exceeds LOG_SLOW_QUERIES_MS (default 250ms in production, 100ms in
 * local) to the 'slow' channel. Set LOG_SLOW_QUERIES=false to disable.
 *
 * The SQL string is logged; bindings are intentionally excluded to
 * avoid leaking PII (passwords, encrypted casts, etc.) into the log.
 */
class LogSlowQueries
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled()) {
            return $next($request);
        }

        $threshold = $this->thresholdMs();
        $channel   = app()->isLocal() ? 'stderr' : 'slow';

        DB::listen(function ($query) use ($request, $threshold, $channel) {
            if ($query->time >= $threshold) {
                Log::channel($channel)->warning('Slow query', [
                    'ms'         => $query->time,
                    'sql'        => $query->sql,
                    'method'     => $request->method(),
                    'url'        => $request->path(),
                    'request_id' => $request->attributes->get('request_id'),
                ]);
            }
        });

        return $next($request);
    }

    private function enabled(): bool
    {
        // Default: on in local + production; off in testing.
        // Read via config() so the value survives `config:cache` (env()
        // returns null once the config is cached in production).
        return (bool) config('logging.slow_query.enabled', ! app()->environment('testing'));
    }

    private function thresholdMs(): int
    {
        $configured = (int) config('logging.slow_query.threshold_ms', 0);
        if ($configured > 0) {
            return $configured;
        }
        return app()->isLocal() ? 100 : 250;
    }
}
