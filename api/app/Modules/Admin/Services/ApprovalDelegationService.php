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
            ->with(['delegator:id,name,email,role_id', 'delegate:id,name,email,role_id'])
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

        if (! User::whereKey($delegateId)->exists()) {
            throw new BusinessRuleException('The selected delegate does not exist.');
        }

        $delegator = User::query()->with('role')->find($delegatorId);
        if (! $delegator) {
            throw new BusinessRuleException('The selected delegator does not exist.');
        }

        $roleSlug = $data['role_slug'] ?? null;
        if ($roleSlug !== null && $delegator->role?->slug !== $roleSlug) {
            throw new BusinessRuleException('A delegation may only cover the delegator\'s current role.');
        }

        return DB::transaction(function () use ($data, $delegatorId, $delegateId, $roleSlug) {
            $delegation = ApprovalDelegation::create([
                'delegator_user_id' => $delegatorId,
                'delegate_user_id' => $delegateId,
                'role_slug' => $roleSlug,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'reason' => $data['reason'] ?? null,
                'is_active' => true,
            ]);

            return $delegation->load(['delegator', 'delegate']);
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

        $delegation->update(['is_active' => false]);

        return $delegation->fresh(['delegator', 'delegate']);
    }
}
