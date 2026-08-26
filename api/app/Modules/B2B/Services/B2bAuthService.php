<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\SettingsService;
use App\Modules\Admin\Services\LoginHistoryService;
use App\Modules\Auth\Services\AuthAuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 Task 15 (C-4) — Unified auth service for the Supplier + Customer
 * portals. Mirrors AuthService::login lockout + audit semantics but issues a
 * A session-backed customer login or a Sanctum bearer-token supplier login.
 *
 * One service handles both portal user types — the caller passes the model
 * class-string + an audience tag (e.g. 'supplier' / 'customer') used to namespace
 * audit/log event names ('supplier.login.success', 'customer.login.failed', ...).
 */
class B2bAuthService
{
    public function __construct(
        private readonly LoginHistoryService $loginHistory,
        private readonly SettingsService $settings,
        private readonly AuthAuditLogger $audit,
    ) {}

    /**
     * Authenticate a portal user and return the user plus an optional token.
     * Customer sessions leave the token null; supplier bearer auth receives it.
     *
     * @template TUser of \Illuminate\Database\Eloquent\Model
     * @param class-string<TUser> $modelClass
     * @return array{token: string|null, user: TUser}
     */
    public function login(
        string $modelClass,
        string $email,
        string $password,
        Request $request,
        string $tokenName,
        string $audience,
        ?string $sessionGuard = null,
    ): array {
        // NOTE on LoginHistoryService: it strictly types the first arg as
        // ?\App\Modules\Auth\Models\User. Portal users are NOT internal users,
        // so we always pass null and namespace the actor identity into the
        // `reason` column. The audit_logs row + auth log channel still capture
        // the portal user_id + email; LoginHistory acts as a best-effort
        // attempt-level audit keyed on the email_attempted column.

        $email = strtolower(trim($email));

        /** @var Model|null $candidate */
        $candidate = $modelClass::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $candidate) {
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_CREDENTIALS, "{$audience}_unknown_email");
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        $result = DB::transaction(function () use ($candidate, $modelClass, $password, $tokenName, $sessionGuard): array {
            /** @var Model|null $user */
            $user = $modelClass::query()->lockForUpdate()->find($candidate->getKey());
            if (! $user) {
                return ['status' => 'unknown', 'user' => null, 'token' => null];
            }

            if (! $user->is_active) {
                return ['status' => 'inactive', 'user' => $user, 'token' => null];
            }

            // A lock is a strike window, not a permanent strike counter. Once
            // the window expires, clear both values while the row is still
            // locked so the next failed attempt starts at strike one.
            if ($user->locked_until !== null && $user->locked_until->isPast()) {
                $user->forceFill([
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
            }

            if ($user->isLocked()) {
                return [
                    'status' => 'locked',
                    'user' => $user,
                    'token' => null,
                    'remaining' => (int) max(now()->diffInMinutes($user->locked_until, false), 0),
                ];
            }

            if (! Hash::check($password, $user->password)) {
                $attempts = (int) $user->failed_login_attempts + 1;
                $maxAttempts = $this->settings->requiredInt('security.max_login_attempts', 1);
                $crossedThreshold = $attempts >= $maxAttempts;

                $user->forceFill([
                    'failed_login_attempts' => $attempts,
                    'locked_until' => $crossedThreshold
                        ? now()->addMinutes($this->settings->requiredInt('security.lockout_minutes', 1))
                        : null,
                ])->save();

                return [
                    'status' => 'failed',
                    'user' => $user,
                    'token' => null,
                    'crossed_threshold' => $crossedThreshold,
                ];
            }

            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'last_login_at' => now(),
            ])->save();

            if ($sessionGuard === null) {
                // One token per supplier session — revoke prior tokens while
                // the user row is locked so concurrent logins cannot interleave
                // revoke/create and leave two active tokens behind.
                $user->tokens()->delete();
                $token = $user->createToken($tokenName)->plainTextToken;
            } else {
                $token = null;
            }

            return ['status' => 'success', 'user' => $user, 'token' => $token];
        });

        if ($result['status'] === 'unknown') {
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_CREDENTIALS, "{$audience}_unknown_email");
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        /** @var Model $user */
        $user = $result['user'];

        if ($result['status'] === 'inactive') {
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_INACTIVE, "{$audience}_inactive");
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if ($result['status'] === 'locked') {
            $remaining = $result['remaining'] ?? 0;
            $this->logAuthEvent("{$audience}.login.locked", $user, $request);
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_LOCKED, "{$audience}_locked");
            abort(423, "Account locked. Try again in {$remaining} minutes.");
        }

        if ($result['status'] === 'failed') {
            $this->logAuthEvent("{$audience}.login.failed", $user, $request);
            if (($result['crossed_threshold'] ?? false) === true) {
                $this->logAuthEvent("{$audience}.login.locked_threshold", $user, $request);
            }
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_CREDENTIALS, "{$audience}_invalid_password");
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if ($sessionGuard !== null) {
            Auth::guard($sessionGuard)->login($user);
            $request->session()->regenerate();
        }

        $this->logAuthEvent("{$audience}.login.success", $user, $request);
        $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_SUCCESS, $audience);

        return ['token' => $result['token'], 'user' => $user];
    }

    /**
     * Delegates to AuthAuditLogger::portal(), which writes the same row plus the
     * actor_type / source_command / correlation_id this method used to omit.
     *
     * This used to hold a second, independent copy of the action-compaction logic
     * ('portal_login.ok' for any .success event), justified by the same comment
     * AuthAuditLogger carried: "audit_logs.action is intentionally bounded to 20
     * characters". Migration 0176 widened that column to varchar(40), so both
     * copies were reasoning from a premise that had stopped being true, and the
     * real event names ('supplier.login.success', 22 chars) fit comfortably. The
     * portal auth tests query for the real names and found nothing.
     */
    private function logAuthEvent(string $event, Model $user, Request $request): void
    {
        $this->audit->portal($event, $user, $request);
    }
}
