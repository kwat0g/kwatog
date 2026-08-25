<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Common\Services\TemporaryPasswordGenerator;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Mail\PortalAccessInvitationMail;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class PortalInvitationService
{
    public function __construct(private readonly TemporaryPasswordGenerator $temporaryPasswords) {}

    /** @return array{user: CustomerPortalUser, temporary_password: string} */
    public function inviteCustomer(Customer $customer, string $name, string $email): array
    {
        $password = $this->temporaryPasswords->generate();
        $user = CustomerPortalUser::withTrashed()->firstOrNew(['email' => strtolower(trim($email))]);
        $user->forceFill([
            'customer_id' => $customer->id,
            'name' => trim($name),
            'email' => strtolower(trim($email)),
            'password' => $password,
            'is_active' => true,
            'must_change_password' => true,
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'password_changed_at' => null,
        ]);
        if ($user->trashed()) {
            $user->restore();
        }
        $user->save();

        $this->queueInvitation($user->email, $user->name, 'customer', $password, [
            'permission' => 'accounting.customers.manage',
            'link_to' => '/accounting/customers/'.$customer->hash_id,
            'entity_type' => 'customer_portal_user',
            'entity_id' => $user->hash_id,
        ]);

        return ['user' => $user, 'temporary_password' => $password];
    }

    /** @return array{user: SupplierPortalUser, temporary_password: string} */
    public function inviteSupplier(Vendor $vendor, string $name, string $email): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $password = $this->temporaryPasswords->generate();
        $user = DB::transaction(function () use ($vendor, $name, $email, $password): SupplierPortalUser {
            $user = SupplierPortalUser::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($user && (int) $user->vendor_id !== (int) $vendor->id) {
                throw new BusinessRuleException('This email is already assigned to a different supplier. Revoke the existing account before assigning it elsewhere.');
            }

            if ($user && (! $user->is_active || $user->trashed())) {
                throw new BusinessRuleException('This supplier account is inactive. Reactivate it before sending a new invitation.');
            }

            $user ??= new SupplierPortalUser();
            $user->forceFill([
                'vendor_id' => $vendor->id,
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_active' => true,
                'must_change_password' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => null,
            ]);
            if ($user->trashed()) {
                $user->restore();
            }
            $user->save();
            // A new invitation is a credential rotation. Do not leave an old
            // bearer token usable while the recipient receives a new one.
            $user->tokens()->delete();

            return $user;
        });

        $this->queueInvitation($user->email, $user->name, 'supplier', $password, [
            'permission' => 'b2b.portal_access.manage',
            'link_to' => '/accounting/vendors/'.$vendor->hash_id,
            'entity_type' => 'supplier_portal_user',
            'entity_id' => $user->hash_id,
        ]);

        return ['user' => $user, 'temporary_password' => $password];
    }

    /** @param array<string, string> $context */
    private function queueInvitation(string $email, string $name, string $portalType, string $password, array $context): void
    {
        try {
            Mail::to($email)->queue(new PortalAccessInvitationMail(
                $portalType,
                $name,
                $password,
                app(EmailDeliveryFailureNotifier::class)->userIdsWithPermission($context['permission']),
            ));
        } catch (\Throwable $e) {
            app(EmailDeliveryFailureNotifier::class)->notifyPermission(
                $context['permission'],
                'Portal access invitation',
                "The {$portalType} portal invitation for {$name} could not be queued. Use the returned temporary password through an approved channel and ask the contact to reset it after signing in.",
                $context + ['reason' => $e->getMessage()],
            );
        }
    }

}
