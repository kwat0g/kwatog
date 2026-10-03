<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * OGAMI-013 — CRUD for approval delegations.
 *
 * Self-service: any user manages delegations where they are the delegator.
 * system_admin may manage delegations for anyone (delegator_user_id explicit).
 */
class ApprovalDelegationService
{
    /**
     * The grant that lets an actor read and revoke delegations belonging to
     * someone else. Named once so the three call sites cannot drift.
     */
    private const MANAGE_ANY = 'admin.delegations.manage_any';

    /**
     * List delegations visible to $actor. Admins see all; everyone else sees
     * delegations they granted or that were granted to them.
     */
    public function list(User $actor): Collection
    {
        $q = ApprovalDelegation::query()
            ->with([
                'delegator:id,name,is_active,role_id',
                'delegator.role:id,slug',
                'delegate:id,name,is_active',
            ])
            ->orderByDesc('id');

        // hasPermission short-circuits for system_admin, so the administrator
        // still sees everything — but now so does any role granted the
        // permission, without this service knowing a role name.
        if (! $actor->hasPermission(self::MANAGE_ANY)) {
            $q->where(function ($w) use ($actor): void {
                $w->where('delegator_user_id', $actor->id)
                    ->orWhere('delegate_user_id', $actor->id);
            });
        }

        return $q->get();
    }

    public function create(array $data, User $actor): ApprovalDelegation
    {
        $canManageAny = $actor->hasPermission(self::MANAGE_ANY);

        // Delegator defaults to the acting user. Only a holder of
        // admin.delegations.manage_any may set it to someone else, so a normal
        // user can only delegate THEIR OWN authority.
        $delegatorId = $actor->id;
        if ($canManageAny && ! empty($data['delegator_user_id'])) {
            $delegatorId = HashIdFilter::decode($data['delegator_user_id'], User::class)
                ?? (int) $data['delegator_user_id'];
        }

        $delegateId = HashIdFilter::decode($data['delegate_user_id'], User::class)
            ?? (int) $data['delegate_user_id'];

        if ($delegateId === $delegatorId) {
            throw new BusinessRuleException('A user cannot delegate approval authority to themselves.');
        }

        $roleSlug = $data['role_slug'] ?? null;

        return DB::transaction(function () use ($data, $delegatorId, $delegateId, $roleSlug) {
            $users = User::query()
                ->whereKey([$delegatorId, $delegateId])
                ->orderBy('id')
                ->lockForUpdate()
                ->with(['role', 'employee:id,department_id'])
                ->get()
                ->keyBy('id');

            $delegate = $users->get($delegateId);
            if (! $delegate) {
                throw new BusinessRuleException('The selected delegate does not exist.');
            }
            if (! $delegate->is_active) {
                throw new BusinessRuleException('The selected delegate must be an active user.');
            }

            $delegator = $users->get($delegatorId);
            if (! $delegator) {
                throw new BusinessRuleException('The selected delegator does not exist.');
            }
            if ($roleSlug !== null && $delegator->role?->slug !== $roleSlug) {
                throw new BusinessRuleException('A delegation may only cover the delegator\'s current role.');
            }
            $coveredRole = $roleSlug ?? $delegator->role?->slug;
            $delegatorDepartmentId = $delegator->employee?->department_id;
            $delegateDepartmentId = $delegate->employee?->department_id;
            if ($coveredRole === 'department_head'
                && $delegatorDepartmentId !== $delegateDepartmentId) {
                throw new BusinessRuleException('Department head approval cover must be assigned within the same department.');
            }

            $existing = ApprovalDelegation::query()
                ->where('delegator_user_id', $delegatorId)
                ->where('delegate_user_id', $delegateId)
                ->where('role_slug', $roleSlug)
                ->whereDate('starts_at', $data['starts_at'])
                ->whereDate('ends_at', $data['ends_at'])
                ->first();

            if ($existing) {
                if ($existing->is_active && $existing->reason === ($data['reason'] ?? null)) {
                    return $existing->load([
                        'delegator:id,name,is_active,role_id',
                        'delegator.role:id,slug',
                        'delegate:id,name,is_active',
                    ]);
                }

                $message = $existing->is_active
                    ? 'An active approval cover already exists for this delegate, role, and date range.'
                    : 'This approval cover was already revoked. Choose a new date range.';

                throw new BusinessRuleException($message);
            }

            $delegation = ApprovalDelegation::create([
                'delegator_user_id' => $delegatorId,
                'delegate_user_id' => $delegateId,
                'role_slug' => $roleSlug,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'reason' => $data['reason'] ?? null,
                'is_active' => true,
            ]);

            return $delegation->load([
                'delegator:id,name,is_active,role_id',
                'delegator.role:id,slug',
                'delegate:id,name,is_active',
            ]);
        });
    }

    /**
     * Revoke (soft-disable) a delegation. Only the delegator or an admin may.
     */
    public function revoke(ApprovalDelegation $delegation, User $actor): ApprovalDelegation
    {
        if (! $actor->hasPermission(self::MANAGE_ANY) && $delegation->delegator_user_id !== $actor->id) {
            throw new BusinessRuleException('Only the delegator or an administrator may revoke this delegation.');
        }

        return DB::transaction(function () use ($delegation): ApprovalDelegation {
            $delegation->update(['is_active' => false]);

            return $delegation->fresh([
                'delegator:id,name,is_active,role_id',
                'delegator.role:id,slug',
                'delegate:id,name,is_active',
            ]);
        });
    }
}
