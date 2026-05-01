<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use App\Common\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts with 403 (code: feature_disabled) if the named module is toggled off.
 *
 * Usage: ->middleware('feature:hr')
 */
class CheckFeature
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $enabled = (bool) $this->settings->get("modules.{$feature}", true);

        if (! $enabled) {
            return response()->json([
                'message' => "The {$feature} module is currently disabled.",
                'code'    => 'feature_disabled',
                'module'  => $feature,
            ], 403);
        }

        return $next($request);
    }
}
