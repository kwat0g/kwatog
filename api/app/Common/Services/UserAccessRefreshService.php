<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Modules\Admin\Events\UserAccessChanged;
use Illuminate\Support\Facades\DB;

/** Stages bounded, exact-user authorization refresh batches in the current transaction. */
final class UserAccessRefreshService
{
    private const MAX_CHANNELS_PER_EVENT = 50;

    /** @param iterable<int|string|null> $delegatorIds
     *  @return list<int>
     */
    public function delegateIdsForDelegators(iterable $delegatorIds): array
    {
        $ids = collect($delegatorIds)
            ->filter(static fn (int|string|null $id): bool => $id !== null && (int) $id > 0)
            ->map(static fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $today = now()->toDateString();

        return DB::table('approval_delegations as delegation')
            ->join('users as delegate', 'delegate.id', '=', 'delegation.delegate_user_id')
            ->whereIn('delegation.delegator_user_id', $ids)
            ->where('delegation.is_active', true)
            ->whereDate('delegation.starts_at', '<=', $today)
            ->whereDate('delegation.ends_at', '>=', $today)
            ->where('delegate.is_active', true)
            ->distinct()
            ->pluck('delegation.delegate_user_id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Delegates whose current delegated approval permissions depend on this role.
     *
     * @return list<int>
     */
    public function activeDelegateIdsForRole(int $roleId, string $roleSlug): array
    {
        $today = now()->toDateString();

        return DB::table('approval_delegations as delegation')
            ->join('users as delegator', 'delegator.id', '=', 'delegation.delegator_user_id')
            ->join('users as delegate', 'delegate.id', '=', 'delegation.delegate_user_id')
            ->where('delegator.role_id', $roleId)
            ->where('delegator.is_active', true)
            ->where('delegate.is_active', true)
            ->where('delegation.is_active', true)
            ->whereDate('delegation.starts_at', '<=', $today)
            ->whereDate('delegation.ends_at', '>=', $today)
            ->where(function ($query) use ($roleSlug): void {
                $query->whereNull('delegation.role_slug')
                    ->orWhere('delegation.role_slug', $roleSlug);
            })
            ->distinct()
            ->pluck('delegation.delegate_user_id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->all();
    }

    /** @param iterable<int|string|null> $userIds */
    public function stageForUsers(iterable $userIds): void
    {
        $hashIds = collect($userIds)
            ->filter(static fn (int|string|null $id): bool => $id !== null && (int) $id > 0)
            ->map(static fn (int|string $id): string => (string) app('hashids')->encode((int) $id))
            ->unique()
            ->values()
            ->all();

        foreach (array_chunk($hashIds, self::MAX_CHANNELS_PER_EVENT) as $batch) {
            app(OutboxService::class)->record(new UserAccessChanged($batch));
        }
    }
}
