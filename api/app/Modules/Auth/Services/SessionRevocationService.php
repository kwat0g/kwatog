<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

final class SessionRevocationService
{
    /**
     * Revoke every database-backed web session except the browser performing
     * a self-service password change. The caller is already inside its
     * credential mutation transaction.
     */
    public function revokeOtherSessions(User $user, ?string $currentSessionId): void
    {
        $query = DB::table('sessions')->where('user_id', $user->getKey());

        if ($currentSessionId !== null && $currentSessionId !== '') {
            $query->where('id', '<>', $currentSessionId);
        }

        $query->delete();
    }

    /** Revoke every database-backed web session for a reset-link mutation. */
    public function revokeAllSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
    }
}
