<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class PortalAccessService
{
    public function __construct(
        private readonly PortalInvitationService $invitations,
        private readonly PortalAccessRealtimeService $realtime,
    ) {}

    /**
     * @param  array{search?: string, status?: string, vendor_id?: string, per_page?: int}  $filters
     */
    public function suppliers(array $filters): LengthAwarePaginator
    {
        $query = SupplierPortalUser::withTrashed()
            ->with('vendor:id,name,is_active')
            ->addSelect([
                'resends_today' => DB::table('audit_logs')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('audit_logs.model_id', 'supplier_portal_users.id')
                    ->where('audit_logs.model_type', (new SupplierPortalUser())->getMorphClass())
                    ->where('audit_logs.action', 'portal_user.resent')
                    ->where('audit_logs.created_at', '>=', now()->startOfDay())
                    ->where('audit_logs.created_at', '<', now()->addDay()->startOfDay()),
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.strtolower(trim($search)).'%';
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
                });
            })
            ->when($filters['vendor_id'] ?? null, function (Builder $query, string $vendorHash): void {
                $query->where('vendor_id', Vendor::decodeHash($vendorHash));
            });

        $this->applyStatusFilter($query, $filters['status'] ?? null);

        return $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->withQueryString();
    }

    /**
     * Customer counterpart of suppliers() — the customer portal accounts are
     * administrable from the same Portal Access screen as suppliers.
     *
     * @param  array{search?: string, status?: string, customer_id?: string, per_page?: int}  $filters
     */
    public function customers(array $filters): LengthAwarePaginator
    {
        $query = CustomerPortalUser::withTrashed()
            ->with('customer:id,name,is_active')
            ->addSelect([
                'resends_today' => DB::table('audit_logs')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('audit_logs.model_id', 'customer_portal_users.id')
                    ->where('audit_logs.model_type', (new CustomerPortalUser())->getMorphClass())
                    ->where('audit_logs.action', 'portal_user.resent')
                    ->where('audit_logs.created_at', '>=', now()->startOfDay())
                    ->where('audit_logs.created_at', '<', now()->addDay()->startOfDay()),
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.strtolower(trim($search)).'%';
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
                });
            })
            ->when($filters['customer_id'] ?? null, function (Builder $query, string $customerHash): void {
                $query->where('customer_id', Customer::decodeHash($customerHash));
            });

        $this->applyStatusFilter($query, $filters['status'] ?? null);

        return $query
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)))
            ->withQueryString();
    }

    public function inviteSupplier(
        Vendor $vendor,
        string $name,
        string $email,
        User $actor,
        Request $request,
        ?bool $isAuthorizedSignatory = null,
    ): SupplierPortalUser {
        $result = $this->invitations->inviteSupplier($vendor, $name, $email, $isAuthorizedSignatory);
        $user = $result['user']->load('vendor:id,name,is_active');

        $this->record($user, 'portal_user.invited', null, [
            'vendor_id' => $user->vendor_id,
            'email' => $user->email,
            'is_active' => true,
            'is_authorized_signatory' => (bool) $user->is_authorized_signatory,
        ], $actor, $request);

        return $user;
    }

    public function inviteCustomer(
        Customer $customer,
        string $name,
        string $email,
        User $actor,
        Request $request,
        ?bool $isAuthorizedSignatory = null,
    ): CustomerPortalUser {
        $result = $this->invitations->inviteCustomer($customer, $name, $email, $isAuthorizedSignatory);
        $user = $result['user']->load('customer:id,name,is_active');

        $this->record($user, 'portal_user.invited', null, [
            'customer_id' => $user->customer_id,
            'email' => $user->email,
            'is_active' => true,
            'is_authorized_signatory' => (bool) $user->is_authorized_signatory,
        ], $actor, $request);

        return $user;
    }

    public function resendSupplier(
        SupplierPortalUser $portalUser,
        User $actor,
        Request $request,
    ): SupplierPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): SupplierPortalUser {
            $portalUser = SupplierPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
            if (! $portalUser->is_active || $portalUser->trashed()) {
                throw new BusinessRuleException('Reactivate the supplier account before sending a new invitation.');
            }
            if (! $portalUser->must_change_password) {
                throw new BusinessRuleException('This contact has already completed first sign-in.');
            }
            $this->assertResendAvailable($portalUser);

            $portalUser = $portalUser->load('vendor:id,name,is_active');
            $vendor = $portalUser->vendor;
            $contactName = trim((string) $vendor?->contact_person);
            $contactEmail = strtolower(trim((string) $vendor?->email));
            if (! $vendor || $contactName === '' || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new BusinessRuleException('Update the supplier contact name and email before resending the invitation.');
            }

            $emailInUse = SupplierPortalUser::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$contactEmail])
                ->where('id', '<>', $portalUser->getKey())
                ->lockForUpdate()
                ->exists();
            if ($emailInUse) {
                throw new BusinessRuleException('The supplier contact email is already assigned to another portal account.');
            }

            $portalUser->forceFill(['name' => $contactName, 'email' => $contactEmail])->save();
            $result = $this->invitations->inviteSupplier($vendor, $contactName, $contactEmail);
            $user = $result['user']->load('vendor:id,name,is_active');

            $this->record($user, 'portal_user.resent', [
                'is_active' => (bool) $portalUser->is_active,
                'must_change_password' => (bool) $portalUser->must_change_password,
            ], [
                'is_active' => true,
                'must_change_password' => true,
            ], $actor, $request);

            return $user->setAttribute('resends_today', $this->resendsToday($user));
        });
    }

    public function deactivate(
        SupplierPortalUser $portalUser,
        User $actor,
        Request $request,
    ): SupplierPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): SupplierPortalUser {
            $user = SupplierPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
            $old = ['is_active' => (bool) $user->is_active, 'deleted_at' => $user->deleted_at?->toIso8601String()];

            $user->forceFill(['is_active' => false, 'locked_until' => null])->save();
            $user->tokens()->delete();
            $this->record($user, 'portal_user.disabled', $old, ['is_active' => false], $actor, $request);
            $this->realtime->revoked($user);

            return $user->load('vendor:id,name,is_active');
        });
    }

    public function deactivateCustomer(
        CustomerPortalUser $portalUser,
        User $actor,
        Request $request,
    ): CustomerPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): CustomerPortalUser {
            $user = CustomerPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
            $old = ['is_active' => (bool) $user->is_active, 'deleted_at' => $user->deleted_at?->toIso8601String()];

            $user->forceFill(['is_active' => false, 'locked_until' => null])->save();
            $user->tokens()->delete();
            $this->record($user, 'portal_user.disabled', $old, ['is_active' => false], $actor, $request);
            $this->realtime->revoked($user);

            return $user->load('customer:id,name,is_active');
        });
    }

    public function reactivate(
        SupplierPortalUser $portalUser,
        User $actor,
        Request $request,
    ): SupplierPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): SupplierPortalUser {
            $user = SupplierPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
            if (! $user->vendor()->lockForUpdate()->where('is_active', true)->exists()) {
                throw new BusinessRuleException('Reactivate the supplier before restoring portal access.');
            }
            $old = [
                'is_active' => (bool) $user->is_active,
                'deleted_at' => $user->deleted_at?->toIso8601String(),
                'must_change_password' => (bool) $user->must_change_password,
            ];

            if ($user->trashed()) {
                $user->restore();
            }
            $user->forceFill([
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'must_change_password' => true,
            ])->save();
            $this->record($user, 'portal_user.enabled', $old, [
                'is_active' => true,
                'must_change_password' => true,
            ], $actor, $request);

            return $user->load('vendor:id,name,is_active');
        });
    }

    public function reactivateCustomer(
        CustomerPortalUser $portalUser,
        User $actor,
        Request $request,
    ): CustomerPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): CustomerPortalUser {
            $user = CustomerPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
            if (! $user->customer()->lockForUpdate()->where('is_active', true)->exists()) {
                throw new BusinessRuleException('Reactivate the customer before restoring portal access.');
            }
            $old = [
                'is_active' => (bool) $user->is_active,
                'deleted_at' => $user->deleted_at?->toIso8601String(),
                'must_change_password' => (bool) $user->must_change_password,
            ];

            if ($user->trashed()) {
                $user->restore();
            }
            $user->forceFill([
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'must_change_password' => true,
            ])->save();
            $this->record($user, 'portal_user.enabled', $old, [
                'is_active' => true,
                'must_change_password' => true,
            ], $actor, $request);

            return $user->load('customer:id,name,is_active');
        });
    }

    public function revokeTokens(
        SupplierPortalUser $portalUser,
        User $actor,
        Request $request,
    ): SupplierPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): SupplierPortalUser {
            $user = SupplierPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
            $count = $user->tokens()->count();
            $user->tokens()->delete();
            $this->realtime->revoked($user);
            $this->record($user, 'portal_tokens.revoke', ['token_count' => $count], ['token_count' => 0], $actor, $request);

            return $user->load('vendor:id,name,is_active');
        });
    }

    public function revokeCustomerTokens(
        CustomerPortalUser $portalUser,
        User $actor,
        Request $request,
    ): CustomerPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): CustomerPortalUser {
            $user = CustomerPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
            $count = $user->tokens()->count();
            $user->tokens()->delete();
            $this->realtime->revoked($user);
            $this->record($user, 'portal_tokens.revoke', ['token_count' => $count], ['token_count' => 0], $actor, $request);

            return $user->load('customer:id,name,is_active');
        });
    }

    public function resendCustomer(
        CustomerPortalUser $portalUser,
        User $actor,
        Request $request,
    ): CustomerPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): CustomerPortalUser {
            $portalUser = CustomerPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
            if (! $portalUser->is_active || $portalUser->trashed()) {
                throw new BusinessRuleException('Reactivate the customer account before sending a new invitation.');
            }
            if (! $portalUser->must_change_password) {
                throw new BusinessRuleException('This contact has already completed first sign-in.');
            }
            $this->assertResendAvailable($portalUser);

            $portalUser = $portalUser->load('customer');
            if (! $portalUser->customer) {
                throw new BusinessRuleException('The customer account has no linked customer; cannot re-invite.');
            }
            $contactName = trim((string) $portalUser->customer->contact_person);
            $contactEmail = strtolower(trim((string) $portalUser->customer->email));
            if ($contactName === '' || filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw new BusinessRuleException('Update the customer contact name and email before resending the invitation.');
            }

            $emailInUse = CustomerPortalUser::withTrashed()
                ->whereRaw('LOWER(email) = ?', [$contactEmail])
                ->where('id', '<>', $portalUser->getKey())
                ->lockForUpdate()
                ->exists();
            if ($emailInUse) {
                throw new BusinessRuleException('The customer contact email is already assigned to another portal account.');
            }

            $portalUser->forceFill(['name' => $contactName, 'email' => $contactEmail])->save();
            $result = $this->invitations->inviteCustomer(
                $portalUser->customer,
                $contactName,
                $contactEmail,
            );
            $user = $result['user']->load('customer:id,name,is_active');

            $this->record($user, 'portal_user.resent', [
                'is_active' => (bool) $portalUser->is_active,
                'must_change_password' => (bool) $portalUser->must_change_password,
            ], [
                'is_active' => true,
                'must_change_password' => true,
            ], $actor, $request);

            return $user->setAttribute('resends_today', $this->resendsToday($user));
        });
    }

    /** Number of invitation resend audit events for this portal account today. */
    public function resendsToday(Model $portalUser): int
    {
        $start = now()->startOfDay();
        $end = $start->copy()->addDay();

        return AuditLog::query()
            ->where('model_type', $portalUser->getMorphClass())
            ->where('model_id', $portalUser->getKey())
            ->where('action', 'portal_user.resent')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }

    private function assertResendAvailable(Model $portalUser): void
    {
        if ($this->resendsToday($portalUser) >= 3) {
            $retryAfter = max(1, (int) now()->diffInSeconds(now()->endOfDay()));
            throw new TooManyRequestsHttpException($retryAfter, 'Daily invitation resend limit reached. Try again tomorrow.');
        }
    }

    /** @param Builder<SupplierPortalUser|CustomerPortalUser> $query */
    private function applyStatusFilter(Builder $query, ?string $status): void
    {
        if ($status === 'inactive') {
            $query->where(function (Builder $nested) use ($query): void {
                $nested->where(fn (Builder $lifecycle) => $lifecycle
                    ->where('is_active', false)
                    ->orWhereNotNull('deleted_at'));
                if ($query->getModel() instanceof CustomerPortalUser) {
                    $nested->orWhereDoesntHave('customer', fn (Builder $customer): Builder => $customer->where('is_active', true));
                } elseif ($query->getModel() instanceof SupplierPortalUser) {
                    $nested->orWhereDoesntHave('vendor', fn (Builder $vendor): Builder => $vendor->where('is_active', true));
                }
            });
        } elseif ($status === 'locked') {
            $query->where('is_active', true)->whereNull('deleted_at')->where('locked_until', '>', now());
        } elseif ($status === 'pending') {
            $query->where('is_active', true)->whereNull('deleted_at')->where('must_change_password', true)
                ->where(function (Builder $nested): void {
                    $nested->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                });
        } elseif ($status === 'active') {
            $query->where('is_active', true)->whereNull('deleted_at')->where('must_change_password', false)
                ->where(function (Builder $nested): void {
                    $nested->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                });
        }

        if (in_array($status, ['active', 'locked', 'pending'], true)) {
            if ($query->getModel() instanceof CustomerPortalUser) {
                $query->whereHas('customer', fn (Builder $customer): Builder => $customer->where('is_active', true));
            } elseif ($query->getModel() instanceof SupplierPortalUser) {
                $query->whereHas('vendor', fn (Builder $vendor): Builder => $vendor->where('is_active', true));
            }
        }
    }

    /**
     * @param  SupplierPortalUser|CustomerPortalUser  $user
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function record(
        Model $user,
        string $action,
        ?array $oldValues,
        ?array $newValues,
        User $actor,
        Request $request,
    ): void {
        AuditLog::create([
            'user_id' => $actor->getKey(),
            'actor_type' => 'user',
            'action' => $action,
            'model_type' => $user->getMorphClass(),
            'model_id' => $user->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'source_command' => $request->route()?->getName(),
            'correlation_id' => $request->attributes->get('request_id') ?? $request->header('X-Request-ID'),
            'created_at' => now(),
        ]);

        $this->realtime->changed();
    }
}
