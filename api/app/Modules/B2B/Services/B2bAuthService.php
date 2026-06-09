<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Models\AuditLog;
use App\Modules\Admin\Services\LoginHistoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 Task 15 (C-4) — Unified auth service for the Supplier + Customer
 * portals. Mirrors AuthService::login lockout + audit semantics but issues a
 * Sanctum Bearer token instead of a session.
 *
 * One service handles both portal user types — the caller passes the model
 * class-string + an audience tag (e.g. 'supplier' / 'customer') used to namespace
 * audit/log event names ('supplier.login.success', 'customer.login.failed', ...).
 */
class B2bAuthService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(private readonly LoginHistoryService $loginHistory) {}

    /**
     * Authenticate a portal user and return the issued token + user model.
     *
     * @template TUser of \Illuminate\Database\Eloquent\Model
     * @param class-string<TUser> $modelClass
     * @return array{token: string, user: TUser}
     */
    public function login(
        string $modelClass,
        string $email,
        string $password,
        Request $request,
        string $tokenName,
        string $audience,
    ): array {
        // NOTE on LoginHistoryService: it strictly types the first arg as
        // ?\App\Modules\Auth\Models\User. Portal users are NOT internal users,
        // so we always pass null and namespace the actor identity into the
        // `reason` column. The audit_logs row + auth log channel still capture
        // the portal user_id + email; LoginHistory acts as a best-effort
        // attempt-level audit keyed on the email_attempted column.

        /** @var Model|null $user */
        $user = $modelClass::query()->where('email', $email)->first();

        if (! $user) {
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_CREDENTIALS, "{$audience}_unknown_email");
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if (! $user->is_active) {
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_INACTIVE, "{$audience}_inactive");
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        if ($user->isLocked()) {
            $remaining = (int) max(now()->diffInMinutes($user->locked_until, false), 0);
            $this->logAuthEvent("{$audience}.login.locked", $user, $request);
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_LOCKED, "{$audience}_locked");
            abort(423, "Account locked. Try again in {$remaining} minutes.");
        }

        if (! Hash::check($password, $user->password)) {
            $user->failed_login_attempts++;
            $crossedThreshold = false;
            if ($user->failed_login_attempts >= self::MAX_ATTEMPTS) {
                $user->locked_until = now()->addMinutes(self::LOCK_MINUTES);
                $crossedThreshold = true;
            }
            $user->save();
            $this->logAuthEvent("{$audience}.login.failed", $user, $request);
            if ($crossedThreshold) {
                $this->logAuthEvent("{$audience}.login.locked_threshold", $user, $request);
            }
            $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_FAILED_CREDENTIALS, "{$audience}_invalid_password");
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
        ])->save();

        // One token per session — revoke prior tokens.
        $user->tokens()->delete();
        $token = $user->createToken($tokenName)->plainTextToken;

        $this->logAuthEvent("{$audience}.login.success", $user, $request);
        $this->loginHistory->record(null, $email, $request, LoginHistoryService::STATUS_SUCCESS, $audience);

        return ['token' => $token, 'user' => $user];
    }

    private function logAuthEvent(string $event, Model $user, Request $request): void
    {
        Log::channel('auth')->info($event, [
            'user_id'    => $user->getKey(),
            'email'      => $user->email,
            'audience'   => $user::class,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        try {
            AuditLog::create([
                // user_id is the internal users.id FK — portal users are a
                // different population, so it stays null. The portal user is
                // identified by model_type + model_id below.
                'user_id'    => null,
                'action'     => $event,
                'model_type' => $user::class,
                'model_id'   => $user->getKey(),
                'old_values' => null,
                'new_values' => ['email' => $user->email],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('auth')->warning('audit_log_mirror_failed', [
                'event'   => $event,
                'user_id' => $user->getKey(),
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
