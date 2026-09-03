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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalAccessService
{
    public function __construct(private readonly PortalInvitationService $invitations) {}

    /**
     * @param array{search?: string, status?: string, vendor_id?: string, per_page?: int} $filters
     */
    public function suppliers(array $filters): LengthAwarePaginator
    {
        $query = SupplierPortalUser::withTrashed()
            ->with('vendor:id,name')
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
     * @param array{search?: string, status?: string, customer_id?: string, per_page?: int} $filters
     */
    public function customers(array $filters): LengthAwarePaginator
    {
        $query = CustomerPortalUser::withTrashed()
            ->with('customer:id,name')
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
    ): SupplierPortalUser {
        $result = $this->invitations->inviteSupplier($vendor, $name, $email);
        $user = $result['user']->load('vendor:id,name');

        $this->record($user, 'portal_user.invited', null, [
            'vendor_id' => $user->vendor_id,
            'email' => $user->email,
            'is_active' => true,
        ], $actor, $request);

        return $user;
    }

    public function resendSupplier(
        SupplierPortalUser $portalUser,
        User $actor,
        Request $request,
    ): SupplierPortalUser {
        if (! $portalUser->is_active || $portalUser->trashed()) {
            throw new BusinessRuleException('Reactivate the supplier account before sending a new invitation.');
        }

        $portalUser = $portalUser->load('vendor:id,name');
        $result = $this->invitations->inviteSupplier($portalUser->vendor, $portalUser->name, $portalUser->email);
        $user = $result['user']->load('vendor:id,name');

        $this->record($user, 'portal_user.resent', [
            'is_active' => (bool) $portalUser->is_active,
            'must_change_password' => (bool) $portalUser->must_change_password,
        ], [
            'is_active' => true,
            'must_change_password' => true,
        ], $actor, $request);

        return $user;
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

            return $user->load('vendor:id,name');
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

            return $user->load('customer:id,name');
        });
    }

    public function reactivate(
        SupplierPortalUser $portalUser,
        User $actor,
        Request $request,
    ): SupplierPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): SupplierPortalUser {
            $user = SupplierPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
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

            return $user->load('vendor:id,name');
        });
    }

    public function reactivateCustomer(
        CustomerPortalUser $portalUser,
        User $actor,
        Request $request,
    ): CustomerPortalUser {
        return DB::transaction(function () use ($portalUser, $actor, $request): CustomerPortalUser {
            $user = CustomerPortalUser::withTrashed()->lockForUpdate()->findOrFail($portalUser->getKey());
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

            return $user->load('customer:id,name');
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
            $this->record($user, 'portal_tokens.revoke', ['token_count' => $count], ['token_count' => 0], $actor, $request);

            return $user->load('vendor:id,name');
        });
    }

    public function resendCustomer(
        CustomerPortalUser $portalUser,
        User $actor,
        Request $request,
    ): CustomerPortalUser {
        if (! $portalUser->is_active || $portalUser->trashed()) {
            throw new BusinessRuleException('Reactivate the customer account before sending a new invitation.');
        }

        $portalUser = $portalUser->load('customer');
        if (! $portalUser->customer) {
            throw new BusinessRuleException('The customer account has no linked customer; cannot re-invite.');
        }
        $result = $this->invitations->inviteCustomer(
            $portalUser->customer,
            $portalUser->name,
            $portalUser->email,
        );
        $user = $result['user']->load('customer:id,name');

        $this->record($user, 'portal_user.resent', [
            'is_active' => (bool) $portalUser->is_active,
            'must_change_password' => (bool) $portalUser->must_change_password,
        ], [
            'is_active' => true,
            'must_change_password' => true,
        ], $actor, $request);

        return $user;
    }

    /** @param Builder<SupplierPortalUser|CustomerPortalUser> $query */
    private function applyStatusFilter(Builder $query, ?string $status): void
    {
        if ($status === 'inactive') {
            $query->where(function (Builder $nested): void {
                $nested->where('is_active', false)->orWhereNotNull('deleted_at');
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
    }

    /**
     * @param SupplierPortalUser|CustomerPortalUser $user
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
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
    }
}
