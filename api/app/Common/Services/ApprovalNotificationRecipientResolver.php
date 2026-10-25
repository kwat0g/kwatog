<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class ApprovalNotificationRecipientResolver
{
    /**
     * Resolve only users eligible for this active step who can open its source record.
     *
     * @param  list<int>  $excludedUserIds
     * @return array<string, Collection<int, User>> keyed by recipient reason
     */
    public function resolveStep(Model $approvable, ApprovalRecord $step, array $excludedUserIds = []): array
    {
        $class = $approvable->getMorphClass();
        if (! $approvable->exists
            || $approvable->getKey() === null
            || ApprovalTypeRegistry::forClass($class) === null
            || $step->approvable_type !== $class
            || (int) $step->approvable_id !== (int) $approvable->getKey()
            || ! $step->is_current
            || $step->action !== 'pending') {
            return [];
        }

        $delegateIds = ApprovalDelegation::activeDelegatesFor($step->role_slug, now());
        $excluded = array_fill_keys(array_map('intval', $excludedUserIds), true);
        $users = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($step, $delegateIds): void {
                $query->whereHas('role', fn ($role) => $role->where('slug', $step->role_slug));
                if ($delegateIds !== []) {
                    $query->orWhereIn('id', $delegateIds);
                }
            })
            ->with('role')
            ->get();

        $resolved = [
            'current_approval_step' => collect(),
            'active_approval_delegate' => collect(),
        ];

        foreach ($users as $user) {
            $id = (int) $user->getKey();
            if (isset($excluded[$id])
                || ! ApprovalTypeRegistry::userCanViewRecord($user, $class, (int) $approvable->getKey())) {
                continue;
            }

            $reason = $user->role?->slug === $step->role_slug
                ? 'current_approval_step'
                : 'active_approval_delegate';
            $resolved[$reason]->push($user);
        }

        return array_filter($resolved, static fn (Collection $recipients): bool => $recipients->isNotEmpty());
    }
}
