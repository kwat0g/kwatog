<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Services\UserAccessRefreshService;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

final class SessionRevocationService
{
    public function __construct(private readonly UserAccessRefreshService $accessRefresh) {}

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

        if ($query->delete() > 0) {
            $this->accessRefresh->stageForUsers([$user->getKey()]);
        }
    }

    /** Revoke every database-backed web session for a reset-link mutation. */
    public function revokeAllSessions(User $user): void
    {
        if (DB::table('sessions')->where('user_id', $user->getKey())->delete() > 0) {
            $this->accessRefresh->stageForUsers([$user->getKey()]);
        }
    }
}
