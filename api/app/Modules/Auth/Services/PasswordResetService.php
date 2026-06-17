<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\PasswordHistory;
use App\Modules\Auth\Models\PasswordResetRequest;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\PasswordResetLinkNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    private const PASSWORD_HISTORY_DEPTH = 3;
    private const EXPIRY_MINUTES = 60;

    public function sendResetLink(string $email, Request $request): void
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->is_active) {
            return;
        }

        $raw  = Str::random(64);
        $hash = hash('sha256', $raw);

        // Atomic so a crash can never leave the user with their old token
        // deleted but no replacement created (silent self-service lockout).
        DB::transaction(function () use ($user, $hash, $request): void {
            PasswordResetRequest::where('user_id', $user->id)->delete();

            PasswordResetRequest::create([
                'user_id'    => $user->id,
                'token_hash' => $hash,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
                'ip_address' => $request->ip(),
            ]);
        });

        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url  = $base . '/reset-password?token=' . $raw;

        $user->notify(new PasswordResetLinkNotification($url));

        Log::channel('auth')->info('password.reset_requested', [
            'user_id' => $user->id,
            'email'   => $user->email,
            'ip'      => $request->ip(),
        ]);
    }

    public function reset(string $token, string $newPassword, Request $request): void
    {
        $hash = hash('sha256', $token);

        $row = PasswordResetRequest::where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            throw ValidationException::withMessages([
                'token' => 'This reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        $user = $row->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'token' => 'This reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        $recent = $user->passwordHistory()->limit(self::PASSWORD_HISTORY_DEPTH)->pluck('password_hash');
        foreach ($recent as $oldHash) {
            if (Hash::check($newPassword, $oldHash)) {
                throw ValidationException::withMessages([
                    'password' => 'You have used this password recently. Choose a different one.',
                ]);
            }
        }

        DB::transaction(function () use ($user, $row, $newPassword): void {
            PasswordHistory::create([
                'user_id'       => $user->id,
                'password_hash' => $user->password,
                'created_at'    => now(),
            ]);

            $user->forceFill([
                'password'              => Hash::make($newPassword),
                'password_changed_at'   => now(),
                'must_change_password'  => false,
                'failed_login_attempts' => 0,
                'locked_until'          => null,
            ])->save();

            $row->forceFill(['used_at' => now()])->save();

            $keepIds = $user->passwordHistory()
                ->limit(self::PASSWORD_HISTORY_DEPTH)
                ->pluck('id')
                ->all();

            if (! empty($keepIds)) {
                $user->passwordHistory()->whereNotIn('id', $keepIds)->delete();
            }
        });

        Log::channel('auth')->info('password.reset', [
            'user_id' => $user->id,
            'email'   => $user->email,
            'ip'      => $request->ip(),
        ]);
    }
}
