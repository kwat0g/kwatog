<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\B2B\Mail\PortalPasswordResetMail;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PortalPasswordResetService
{
    public function requestReset(string $type, string $email): void
    {
        $email = strtolower(trim($email));
        $model = $this->modelClass($type);
        $user = $model::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->first();

        // Deliberately return the same way for unknown/inactive addresses so
        // the portal does not become an account-enumeration oracle.
        if (! $user) {
            return;
        }

        $rawToken = bin2hex(random_bytes(32));
        DB::table('portal_password_reset_tokens')->insert([
            'portal_type' => $type,
            'email' => $email,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(60),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Mail::to($user->email)->queue(new PortalPasswordResetMail(
            $type,
            $rawToken,
            $user->name,
            app(EmailDeliveryFailureNotifier::class)->userIdsWithPermission($this->fallbackPermission($type)),
        ));
    }

    public function reset(string $type, string $rawToken, string $password): void
    {
        $tokenHash = hash('sha256', $rawToken);

        DB::transaction(function () use ($type, $rawToken, $tokenHash, $password): void {
            $token = DB::table('portal_password_reset_tokens')
                ->where('portal_type', $type)
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if (! $token || $token->used_at !== null || now()->greaterThan($token->expires_at)) {
                throw ValidationException::withMessages([
                    'token' => 'This password reset link is invalid or has expired. Request a new link.',
                ]);
            }

            $model = $this->modelClass($type);
            $user = $model::query()
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $token->email)])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'token' => 'This portal account is no longer active. Contact Ogami Philippines support.',
                ]);
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            DB::table('portal_password_reset_tokens')
                ->where('id', $token->id)
                ->update(['used_at' => now(), 'updated_at' => now()]);

            $user->tokens()->delete();
        });
    }

    /** @return class-string<CustomerPortalUser|SupplierPortalUser> */
    private function modelClass(string $type): string
    {
        return match ($type) {
            'customer' => CustomerPortalUser::class,
            'supplier' => SupplierPortalUser::class,
            default => throw ValidationException::withMessages(['type' => 'Unsupported portal type.']),
        };
    }

    private function fallbackPermission(string $type): string
    {
        return $type === 'supplier' ? 'accounting.vendors.view' : 'accounting.customers.view';
    }
}
