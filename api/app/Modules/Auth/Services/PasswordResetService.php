<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\SettingsService;
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
    public function __construct(private readonly SettingsService $settings) {}

    public function sendResetLink(string $email, Request $request): void
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->is_active) {
            return;
        }

        $raw = Str::random(64);
        $hash = hash('sha256', $raw);

        // Atomic so a crash can never leave the user with their old token
        // deleted but no replacement created (silent self-service lockout).
        $expiryMinutes = $this->settings->requiredInt('security.password_reset_expiry_minutes', 5, 10080);
        DB::transaction(function () use ($user, $hash, $request, $expiryMinutes): void {
            PasswordResetRequest::where('user_id', $user->id)->delete();

            PasswordResetRequest::create([
                'user_id' => $user->id,
                'token_hash' => $hash,
                'expires_at' => now()->addMinutes($expiryMinutes),
                'ip_address' => $request->ip(),
            ]);
        });

        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $url = $base.'/reset-password?token='.$raw;

        try {
            $user->notify(new PasswordResetLinkNotification($url, $expiryMinutes));
        } catch (\Throwable $e) {
            app(EmailDeliveryFailureNotifier::class)->notifyUserId(
                $user->id,
                'Password reset email',
                'Your password reset email could not be delivered. Request a new reset link or contact an administrator.',
                ['reason' => 'The email provider rejected or could not deliver the password reset message.'],
            );
            app(EmailDeliveryFailureNotifier::class)->notifyPermission(
                'admin.users.manage',
                'Password reset email',
                "The password reset email for {$user->email} could not be delivered. Confirm the account email address and assist the user through an approved channel.",
                ['reason' => 'The email provider rejected or could not deliver the password reset message.'],
            );
            Log::warning('Password reset email delivery failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('auth')->info('password.reset_requested', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);
    }

    public function reset(string $token, string $newPassword, Request $request): void
    {
        $hash = hash('sha256', $token);
        $historyDepth = $this->settings->requiredInt('security.password_history_depth', 0, 10);

        /** @var User $user */
        $user = DB::transaction(function () use ($hash, $newPassword, $historyDepth): User {
            // Re-read the authoritative token inside the mutation transaction.
            // The row lock serializes consumers of the same token; validity is
            // checked only after the lock so a stale preloaded model cannot
            // redeem a token that another consumer already used.
            $row = PasswordResetRequest::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->used_at !== null || ! $row->expires_at->isFuture()) {
                throw ValidationException::withMessages([
                    'token' => 'This reset link is invalid or has expired. Please request a new one.',
                ]);
            }

            $user = User::query()->lockForUpdate()->find($row->user_id);
            if (! $user) {
                throw ValidationException::withMessages([
                    'token' => 'This reset link is invalid or has expired. Please request a new one.',
                ]);
            }

            $recent = $user->passwordHistory()->limit($historyDepth)->pluck('password_hash');
            foreach ($recent as $oldHash) {
                if (Hash::check($newPassword, $oldHash)) {
                    throw ValidationException::withMessages([
                        'password' => 'You have used this password recently. Choose a different one.',
                    ]);
                }
            }

            PasswordHistory::create([
                'user_id' => $user->id,
                'password_hash' => $user->password,
                'created_at' => now(),
            ]);

            $user->forceFill([
                'password' => Hash::make($newPassword),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $row->forceFill(['used_at' => now()])->save();

            $keepIds = $user->passwordHistory()
                ->limit($historyDepth)
                ->pluck('id')
                ->all();

            if (! empty($keepIds)) {
                $user->passwordHistory()->whereNotIn('id', $keepIds)->delete();
            }

            return $user;
        });

        Log::channel('auth')->info('password.reset', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $request->ip(),
        ]);
    }
}
