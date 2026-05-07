<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\LoginHistory;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;

class LoginHistoryService
{
    public const STATUS_SUCCESS              = 'success';
    public const STATUS_FAILED_CREDENTIALS   = 'failed_credentials';
    public const STATUS_FAILED_LOCKED        = 'failed_locked';
    public const STATUS_FAILED_INACTIVE      = 'failed_inactive';
    public const STATUS_FAILED_PASSWORD_EXP  = 'failed_password_expired';

    public function record(
        ?User $user,
        string $emailAttempted,
        Request $request,
        string $status,
        ?string $reason = null,
    ): LoginHistory {
        return LoginHistory::create([
            'user_id'         => $user?->id,
            'email_attempted' => $emailAttempted,
            'ip_address'      => $request->ip(),
            'user_agent'      => mb_substr((string) $request->userAgent(), 0, 500),
            'status'          => $status,
            'reason'          => $reason,
            'created_at'      => now(),
        ]);
    }
}
