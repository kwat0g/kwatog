<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Models\ApprovalDelegation;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * OGAMI-013 — CRUD for approval delegations.
 *
 * Self-service: any user manages delegations where they are the delegator.
 * system_admin may manage delegations for anyone (delegator_user_id explicit).
 */
class ApprovalDelegationService
{
    /**
     * List delegations visible to $actor. Admins see all; everyone else sees
     * delegations they granted or that were granted to them.
     */
    public function list(User $actor): Collection
    {
        $q = ApprovalDelegation::query()
            ->with(['delegator:id,name,email,role_id', 'delegate:id,name,email,role_id'])
            ->orderByDesc('id');

        if ($actor->role?->slug !== 'system_admin') {
            $q->where(function ($w) use ($actor): void {
                $w->where('delegator_user_id', $actor->id)
                  ->orWhere('delegate_user_id', $actor->id);
            });
        }

        return $q->get();
    }

    public function create(array $data, User $actor): ApprovalDelegation
    {
        $isAdmin = $actor->role?->slug === 'system_admin';

        // Delegator defaults to the acting user. Only an admin may set it to
        // someone else, so a normal user can only delegate THEIR OWN authority.
        $delegatorId = $actor->id;
        if ($isAdmin && ! empty($data['delegator_user_id'])) {
            $delegatorId = HashIdFilter::decode($data['delegator_user_id'], User::class)
                ?? (int) $data['delegator_user_id'];
        }

        $delegateId = HashIdFilter::decode($data['delegate_user_id'], User::class)
            ?? (int) $data['delegate_user_id'];

        if ($delegateId === $delegatorId) {
            throw new RuntimeException('A user cannot delegate approval authority to themselves.');
        }

        if (! User::whereKey($delegateId)->exists()) {
            throw new RuntimeException('The selected delegate does not exist.');
        }

        return DB::transaction(function () use ($data, $delegatorId, $delegateId) {
            $delegation = ApprovalDelegation::create([
                'delegator_user_id' => $delegatorId,
                'delegate_user_id'  => $delegateId,
                'role_slug'         => $data['role_slug'] ?? null,
                'starts_at'         => $data['starts_at'],
                'ends_at'           => $data['ends_at'],
                'reason'            => $data['reason'] ?? null,
                'is_active'         => true,
            ]);

            return $delegation->load(['delegator', 'delegate']);
        });
    }

    /**
     * Revoke (soft-disable) a delegation. Only the delegator or an admin may.
     */
    public function revoke(ApprovalDelegation $delegation, User $actor): ApprovalDelegation
    {
        $isAdmin = $actor->role?->slug === 'system_admin';
        if (! $isAdmin && $delegation->delegator_user_id !== $actor->id) {
            throw new RuntimeException('Only the delegator or an administrator may revoke this delegation.');
        }

        $delegation->update(['is_active' => false]);

        return $delegation->fresh(['delegator', 'delegate']);
    }
}
