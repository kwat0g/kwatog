<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Models\AuditLog;
use App\Modules\Admin\Services\LoginHistoryService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly LoginHistoryService $loginHistory,
        private readonly DashboardLayoutService $dashboardLayouts,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Authenticate by email + password. Increments lock counter on failure,
     * resets on success, regenerates the session, and logs the event.
     *
     * @return User the authenticated user
     */
    public function login(string $email, string $password, Request $request): User
    {
        // NOTE: do NOT wrap the entire login flow in DB::transaction. Failed-
        // path mutations (the failed_login_attempts counter, locked_until
        // stamp, and login_history audit rows) MUST survive the
        // ValidationException throws below — otherwise a rolling-back
        // transaction silently disables the 5-strikes lockout. Each path
        // commits independently. The success path uses a small inner
        // transaction for the counter reset + last_activity update.

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if (! $user) {
            // Unknown-email attempts are tracked via LoginHistory; no audit_logs
            // row (which requires a real user_id at model_id for filterability).
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_CREDENTIALS, 'unknown_email');
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if (! $user->is_active) {
            $this->loginHistory->record($user, $email, $request, LoginHistoryService::STATUS_FAILED_INACTIVE, 'account_inactive');
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if ($user->isLocked()) {
            $remaining = (int) max(now()->diffInMinutes($user->locked_until, false), 0);
            $this->logAuthEvent('login.locked', $user, $request);
            $this->loginHistory->record($user, $email, $request, LoginHistoryService::STATUS_FAILED_LOCKED, 'account_locked');
            abort(423, "Account locked. Try again in {$remaining} minutes.");
        }

        if (! Hash::check($password, $user->password)) {
            $user->failed_login_attempts++;
            $crossedThreshold = false;
            $maxAttempts = (int) $this->settings->get('security.max_login_attempts', 5);
            if ($user->failed_login_attempts >= $maxAttempts) {
                $lockMinutes = (int) $this->settings->get('security.lockout_minutes', 15);
                $user->locked_until = now()->addMinutes($lockMinutes);
                $crossedThreshold = true;
            }
            $user->save();
            $this->logAuthEvent('login.failed', $user, $request);
            if ($crossedThreshold) {
                $this->logAuthEvent('login.locked_threshold', $user, $request);
            }
            $this->loginHistory->record($user, $email, $request, LoginHistoryService::STATUS_FAILED_CREDENTIALS, 'invalid_password');
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        DB::transaction(function () use ($user) {
            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until'          => null,
                'last_activity'         => now(),
            ])->save();
        });

        Auth::login($user);
        $request->session()->regenerate();

        $this->logAuthEvent('login.success', $user, $request);
        $this->loginHistory->record($user, $email, $request, LoginHistoryService::STATUS_SUCCESS);

        // Series R — Task R4: clone the role's default dashboard layout
        // to the user the first time they log in. Idempotent: subsequent
        // logins are no-ops because user-owned rows already exist.
        // Skipped silently for system_admin (sees every widget anyway).
        if ($user->role?->slug !== 'system_admin') {
            try {
                $this->dashboardLayouts->cloneRoleDefaultToUser($user);
            } catch (\Throwable $e) {
                // Never block login on a dashboard hiccup — log and proceed.
                Log::channel('auth')->warning('dashboard.clone_failed', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $user->fresh(['role.permissions']);
    }

    public function logout(Request $request): void
    {
        $user = Auth::user();
        if ($user) {
            $this->logAuthEvent('logout', $user, $request);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Validate + store a new password. Enforces history (last N) and policy.
     */
    public function changePassword(User $user, string $current, string $new, Request $request): void
    {
        DB::transaction(function () use ($user, $current, $new, $request) {
            if (! Hash::check($current, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
            }

            $historyDepth = (int) $this->settings->get('security.password_history_depth', 3);
            $recent = $user->passwordHistory()->limit($historyDepth)->pluck('password_hash');
            foreach ($recent as $oldHash) {
                if (Hash::check($new, $oldHash)) {
                    throw ValidationException::withMessages([
                        'new_password' => 'You have used this password recently. Choose a different one.',
                    ]);
                }
            }

            // Store the OLD hash to history before replacing
            PasswordHistory::create([
                'user_id'       => $user->id,
                'password_hash' => $user->password,
                'created_at'    => now(),
            ]);

            $user->forceFill([
                'password'              => Hash::make($new),
                'password_changed_at'   => now(),
                'must_change_password'  => false,
            ])->save();

            // Trim history beyond depth
            $keepIds = $user->passwordHistory()
                ->limit($historyDepth)
                ->pluck('id')
                ->all();
            if (! empty($keepIds)) {
                $user->passwordHistory()->whereNotIn('id', $keepIds)->delete();
            }

            $this->logAuthEvent('password.changed', $user, $request);
        });
    }

    private function logAuthEvent(string $event, User $user, Request $request): void
    {
        Log::channel('auth')->info($event, [
            'user_id'    => $user->id,
            'email'      => $user->email,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Mirror to audit_logs so the Admin Audit Log UI can surface auth
        // events. Best-effort — never block authentication on a logging
        // failure. action column was widened to varchar(40) in 0176 so the
        // long-form event name is preserved verbatim alongside the file log.
        try {
            AuditLog::create([
                'user_id'    => $user->id,
                'action'     => $event,
                'model_type' => 'auth.event',
                'model_id'   => $user->id,
                'old_values' => null,
                'new_values' => ['email' => $user->email],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('auth')->warning('audit_log_mirror_failed', [
                'event'   => $event,
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
