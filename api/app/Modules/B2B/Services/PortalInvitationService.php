<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\EmailDeliveryFailureNotifier;
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
    /** @return array{user: CustomerPortalUser} */
    public function inviteCustomer(Customer $customer, string $name, string $email): array
    {
        if (! $customer->is_active || $customer->trashed()) {
            throw new BusinessRuleException('Customer portal access cannot be invited for an inactive customer.');
        }

        $name = trim($name);
        $email = strtolower(trim($email));
        $unknownPassword = bin2hex(random_bytes(32));
        $user = DB::transaction(function () use ($customer, $name, $email, $unknownPassword): CustomerPortalUser {
            // customer_portal_users.email is globally UNIQUE while the row holds
            // a single customer_id, so this lookup IS the tenant boundary. The
            // match must normalise case the same way the write does, or the
            // boundary is bypassable by capitalisation; the row lock keeps two
            // concurrent invitations from interleaving the ownership check with
            // the write.
            $user = CustomerPortalUser::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            // Without this check, customer B inviting an address already held by
            // customer A silently re-pointed A's portal login at B's orders,
            // invoices and deliveries and reset it to a password B chose — an
            // account takeover of A plus a denial of A's own access.
            if ($user && (int) $user->customer_id !== (int) $customer->id) {
                throw new BusinessRuleException('This email is already assigned to a different customer. Remove the existing portal account before assigning it elsewhere.');
            }

            $user ??= new CustomerPortalUser();
            $user->forceFill([
                'customer_id' => $customer->id,
                'name' => $name,
                'email' => $email,
                'password' => $unknownPassword,
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
            // A new invitation is a credential rotation. The customer guard is
            // session-backed, but the model still carries HasApiTokens and rows
            // may predate that migration, so do not leave a stale bearer token
            // behind a freshly rotated password.
            $user->tokens()->delete();

            return $user;
        });

        $invitation = app(PortalPasswordResetService::class)->issueInvitationToken('customer', $user);
        $this->queueInvitation($invitation['email'], $invitation['name'], 'customer', $invitation['token'], [
            'permission' => 'accounting.customers.manage',
            'link_to' => '/accounting/customers/'.$customer->hash_id,
            'entity_type' => 'customer_portal_user',
            'entity_id' => $user->hash_id,
        ]);

        return ['user' => $user];
    }

    /** @return array{user: SupplierPortalUser} */
    public function inviteSupplier(Vendor $vendor, string $name, string $email): array
    {
        if (! $vendor->is_active || $vendor->trashed()) {
            throw new BusinessRuleException('Supplier portal access cannot be invited for an inactive supplier.');
        }

        $name = trim($name);
        $email = strtolower(trim($email));
        $unknownPassword = bin2hex(random_bytes(32));
        $user = DB::transaction(function () use ($vendor, $name, $email, $unknownPassword): SupplierPortalUser {
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
                'password' => $unknownPassword,
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

        $invitation = app(PortalPasswordResetService::class)->issueInvitationToken('supplier', $user);
        $this->queueInvitation($invitation['email'], $invitation['name'], 'supplier', $invitation['token'], [
            'permission' => 'b2b.portal_access.manage',
            'link_to' => '/accounting/vendors/'.$vendor->hash_id,
            'entity_type' => 'supplier_portal_user',
            'entity_id' => $user->hash_id,
        ]);

        return ['user' => $user];
    }

    /** @param array<string, string> $context */
    private function queueInvitation(string $email, string $name, string $portalType, string $token, array $context): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            app(EmailDeliveryFailureNotifier::class)->notifyPermission(
                $context['permission'],
                'Portal access invitation',
                "The {$portalType} portal invitation for {$name} could not be sent because the email address is not usable. Verify the address and resend the secure setup link.",
                $context + ['reason' => 'The recipient email address is not valid.'],
            );

            return;
        }

        try {
            Mail::to($email)->queue(new PortalAccessInvitationMail(
                $portalType,
                $name,
                $token,
                app(EmailDeliveryFailureNotifier::class)->userIdsWithPermission($context['permission']),
            ));
        } catch (\Throwable $e) {
            app(EmailDeliveryFailureNotifier::class)->notifyPermission(
                $context['permission'],
                'Portal access invitation',
                "The {$portalType} portal invitation for {$name} could not be queued. Resend the invitation after verifying the contact's email address.",
                $context + ['reason' => 'The email provider rejected or could not queue the portal invitation.'],
            );
        }
    }

}
