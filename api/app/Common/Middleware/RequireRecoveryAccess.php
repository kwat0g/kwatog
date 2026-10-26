<?php

declare(strict_types=1);

namespace App\Common\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class RequireRecoveryAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! $this->configured()) {
            return response()->json(['message' => 'Recovery access is not configured.'], 503)
                ->header('Cache-Control', 'no-store, private');
        }

        try {
            $claims = json_decode(Crypt::decryptString((string) $request->bearerToken()), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return response()->json(['message' => 'Recovery access is invalid or expired.'], 401)
                ->header('Cache-Control', 'no-store, private');
        }

        if (($claims['scope'] ?? null) !== 'backup-recovery'
            || ! is_int($claims['expires_at'] ?? null)
            || $claims['expires_at'] <= time()
            || ! is_string($claims['secret_hash'] ?? null)
            || ! hash_equals(hash('sha256', (string) config('backup.recovery_secret')), $claims['secret_hash'])) {
            return response()->json(['message' => 'Recovery access is invalid or expired.'], 401)
                ->header('Cache-Control', 'no-store, private');
        }

        return $next($request)->header('Cache-Control', 'no-store, private');
    }

    public static function configured(): bool
    {
        return strlen((string) config('backup.recovery_secret', '')) >= 32;
    }
}
