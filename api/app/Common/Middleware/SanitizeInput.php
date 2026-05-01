<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strips HTML tags from string inputs across all requests.
 * Excludes raw text fields used for rich content (none in Sprint 1).
 */
class SanitizeInput
{
    /**
     * Field names whose contents are intentionally preserved as-is.
     *
     * @var array<int, string>
     */
    private const ALLOW_RAW = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'new_password_confirmation',
        'token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $request->merge($this->sanitize($request->all()));
        return $next($request);
    }

    /**
     * @param  array<mixed>  $input
     * @return array<mixed>
     */
    private function sanitize(array $input): array
    {
        foreach ($input as $key => $value) {
            if (in_array($key, self::ALLOW_RAW, true)) {
                continue;
            }
            if (is_string($value)) {
                $input[$key] = trim(strip_tags($value));
            } elseif (is_array($value)) {
                $input[$key] = $this->sanitize($value);
            }
        }
        return $input;
    }
}
