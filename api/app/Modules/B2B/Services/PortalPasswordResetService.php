<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Services\AuthAuditLogger;
use App\Modules\B2B\Mail\PortalPasswordResetMail;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortalPasswordResetService
{
    public function __construct(
        private readonly PortalPasswordHistoryService $history,
        private readonly AuthAuditLogger $audit,
    ) {}

    public function requestReset(string $type, string $email, Request $request): void
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
        // Invalidate + create under the portal-user row lock. Without the
        // lock, two simultaneous requests can both invalidate the old token
        // set and then each insert a usable token, defeating newest-only
        // semantics.
        /** @var CustomerPortalUser|SupplierPortalUser|null $recipient */
        $recipient = DB::transaction(function () use ($model, $user, $type, $email, $rawToken): ?object {
            $lockedUser = $model::query()->lockForUpdate()->find($user->getKey());
            if (! $lockedUser || ! $lockedUser->is_active || ! $this->parentIsActive($lockedUser)) {
                return null;
            }

            DB::table('portal_password_reset_tokens')
                ->where('portal_type', $type)
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);

            DB::table('portal_password_reset_tokens')->insert([
                'portal_type' => $type,
                'email' => $email,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => now()->addMinutes(60),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $lockedUser;
        });

        if (! $recipient) {
            return;
        }

        $subject = $this->masterRecord($type, $recipient);
        if (! $subject) {
            return;
        }
        $subjectLink = $this->masterRecordLink($subject);

        $this->audit->portal('portal.password.reset_requested', $recipient, $request);

        $notifier = app(EmailDeliveryFailureNotifier::class);
        $fallbackUserIds = $notifier->userIdsWithPermission($this->fallbackPermission($type));

        if (! is_string($recipient->email) || filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
            $notifier->notifyUserIds(
                $fallbackUserIds,
                'Portal password reset email',
                'A portal password reset was requested but the account email is not deliverable. Contact the portal user through an approved channel and verify the account before assisting with access.',
                [
                    'link_to' => $subjectLink,
                    'entity_type' => $type.'_portal_user',
                    'reason' => 'The portal account email address is not valid.',
                ],
                $subject,
            );

            return;
        }

        Mail::to($recipient->email)->queue(new PortalPasswordResetMail(
            $type,
            $rawToken,
            $recipient->name,
            (int) $subject->getKey(),
            $subjectLink,
            $fallbackUserIds,
        ));
    }

    /** @return array{token: string, email: string, name: string} */
    public function issueInvitationToken(string $type, CustomerPortalUser|SupplierPortalUser $user): array
    {
        $model = $this->modelClass($type);
        if (! $user instanceof $model) {
            throw ValidationException::withMessages(['type' => 'Portal type does not match the account.']);
        }

        $rawToken = bin2hex(random_bytes(32));
        $recipient = DB::transaction(function () use ($model, $user, $type, $rawToken): array {
            $recipient = $model::query()->lockForUpdate()->find($user->getKey());
            if (! $recipient || ! $recipient->is_active || ! $this->parentIsActive($recipient)) {
                throw ValidationException::withMessages(['account' => 'This portal account is no longer active.']);
            }

            $email = strtolower((string) $recipient->email);
            DB::table('portal_password_reset_tokens')
                ->where('portal_type', $type)
                ->where('email', $email)
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);

            DB::table('portal_password_reset_tokens')->insert([
                'portal_type' => $type,
                'email' => $email,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => now()->addMinutes(60),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['email' => $email, 'name' => (string) $recipient->name];
        });

        return ['token' => $rawToken] + $recipient;
    }

    public function reset(string $type, string $rawToken, string $password, Request $request): void
    {
        $tokenHash = hash('sha256', $rawToken);

        /** @var CustomerPortalUser|SupplierPortalUser $resetUser */
        $resetUser = DB::transaction(function () use ($type, $tokenHash, $password): CustomerPortalUser|SupplierPortalUser {
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

            if (! $user || ! $this->parentIsActive($user)) {
                throw ValidationException::withMessages([
                    'token' => 'This portal account is no longer active. Contact Ogami Philippines support.',
                ]);
            }

            $this->history->assertAllowed($user, $password);
            $oldPasswordHash = (string) $user->password;

            $user->forceFill([
                'password' => Hash::make($password),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $this->history->record($user, $oldPasswordHash);

            DB::table('portal_password_reset_tokens')
                ->where('portal_type', $type)
                ->where('email', strtolower((string) $token->email))
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);

            $user->tokens()->delete();

            return $user;
        });

        $this->audit->portal('portal.password.reset', $resetUser, $request);
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

    private function masterRecord(string $type, CustomerPortalUser|SupplierPortalUser $user): Customer|Vendor|null
    {
        return match ($type) {
            'customer' => Customer::query()->find($user->customer_id),
            'supplier' => Vendor::query()->find($user->vendor_id),
            default => null,
        };
    }

    private function masterRecordLink(Customer|Vendor $subject): string
    {
        $path = $subject instanceof Vendor ? 'vendors' : 'customers';

        return '/accounting/'.$path.'/'.$subject->hash_id;
    }

    private function parentIsActive(CustomerPortalUser|SupplierPortalUser $user): bool
    {
        return match (true) {
            $user instanceof CustomerPortalUser => $user->customer()->lockForUpdate()->where('is_active', true)->exists(),
            $user instanceof SupplierPortalUser => $user->vendor()->lockForUpdate()->where('is_active', true)->exists(),
        };
    }
}
