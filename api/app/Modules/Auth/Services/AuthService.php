<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;
    private const PASSWORD_HISTORY_DEPTH = 3;

    /**
     * Authenticate by email + password. Increments lock counter on failure,
     * resets on success, regenerates the session, and logs the event.
     *
     * @return User the authenticated user
     */
    public function login(string $email, string $password, Request $request): User
    {
        return DB::transaction(function () use ($email, $password, $request) {
            /** @var User|null $user */
            $user = User::where('email', $email)->first();

            if (! $user || ! $user->is_active) {
                throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
            }

            if ($user->isLocked()) {
                $remaining = (int) max(now()->diffInMinutes($user->locked_until, false), 0);
                $this->logAuthEvent('login.locked', $user, $request);
                abort(423, "Account locked. Try again in {$remaining} minutes.");
            }

            if (! Hash::check($password, $user->password)) {
                $user->failed_login_attempts++;
                if ($user->failed_login_attempts >= self::MAX_ATTEMPTS) {
                    $user->locked_until = now()->addMinutes(self::LOCK_MINUTES);
                    $this->logAuthEvent('login.locked_threshold', $user, $request);
                }
                $user->save();
                $this->logAuthEvent('login.failed', $user, $request);
                throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
            }

            $user->forceFill([
                'failed_login_attempts' => 0,
                'locked_until'          => null,
                'last_activity'         => now(),
            ])->save();

            Auth::login($user);
            $request->session()->regenerate();

            $this->logAuthEvent('login.success', $user, $request);

            return $user->fresh(['role.permissions']);
        });
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

            // Ensure not in last N hashes
            $recent = $user->passwordHistory()->limit(self::PASSWORD_HISTORY_DEPTH)->pluck('password_hash');
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
                ->limit(self::PASSWORD_HISTORY_DEPTH)
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
    }
}
