<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PortalPasswordService
{
    public function change(CustomerPortalUser|SupplierPortalUser $user, string $current, string $new, Request $request): void
    {
        DB::transaction(function () use ($user, $current, $new): void {
            $locked = $user->newQuery()->lockForUpdate()->findOrFail($user->getKey());

            if (! Hash::check($current, $locked->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Current password is incorrect.',
                ]);
            }

            if (Hash::check($new, $locked->password)) {
                throw ValidationException::withMessages([
                    'new_password' => 'The new password must be different from your current password.',
                ]);
            }

            $locked->forceFill([
                'password' => Hash::make($new),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $locked->tokens()->delete();
        });

        Log::channel('auth')->info('portal.password.changed', [
            'portal_user_type' => $user::class,
            'portal_user_id' => $user->getKey(),
            'email' => $user->email,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
