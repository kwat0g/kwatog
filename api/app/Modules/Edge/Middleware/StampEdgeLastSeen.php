<?php

declare(strict_types=1);

namespace App\Modules\Edge\Middleware;

use App\Modules\Edge\Models\EdgeDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StampEdgeLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user();
        if ($device instanceof EdgeDevice) {
            // Save quietly so HasAuditLog doesn't fire on every ingest call.
            $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        }
        return $next($request);
    }
}
