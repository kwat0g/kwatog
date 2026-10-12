<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Services\NotificationService;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * OGAMI-013 — Approval delegation.
 *
 * Lets a user (delegator) nominate another user (delegate) to act on their
 * behalf for approval steps during a date window. A null `role_slug` means the
 * delegate may act for EVERY role the delegator currently holds; a concrete
 * slug scopes the delegation to a single role.
 *
 * @property int $delegator_user_id
 * @property int $delegate_user_id
 * @property string|null $role_slug
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $is_active
 */
class ApprovalDelegation extends Model
{
    use HasAuditLog;
    use HasHashId;

    protected $fillable = [
        'delegator_user_id',
        'delegate_user_id',
        'role_slug',
        'starts_at',
        'ends_at',
        'reason',
        'is_active',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'is_active' => 'boolean',
    ];

    /** @var list<string> */
    private const CUSTOM_APPROVAL_PERMISSIONS = [
        'attendance.ot.approve',
        'inventory.adjust.approve',
        'inventory.costs.approve',
        'inventory.stock_count.manage',
        'payroll.adjustments.approve',
        'payroll.adjustments.reject',
        'payroll.periods.approve',
        'budgeting.approve',
        'accounting.invoices.prebill_approve',
        'purchasing.supplier_listings.review',
        'loans.write_off.approve',
    ];

    /** @var array<string, list<string>> */
    private const CUSTOM_APPROVAL_READ_PERMISSIONS = [
        'attendance.ot.approve' => ['attendance.view'],
        'inventory.adjust.approve' => ['inventory.adjust'],
        'inventory.costs.approve' => ['inventory.costs.review'],
        'inventory.stock_count.manage' => ['inventory.stock_count.view'],
        'payroll.adjustments.approve' => ['payroll.adjustments.view'],
        'payroll.adjustments.reject' => ['payroll.adjustments.view'],
        'payroll.periods.approve' => ['payroll.periods.view'],
        'budgeting.approve' => ['budgeting.view'],
        'accounting.invoices.prebill_approve' => ['accounting.invoices.view'],
        'purchasing.supplier_listings.review' => ['purchasing.supplier_listings.view'],
        'loans.write_off.approve' => ['loans.view'],
    ];

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id');
    }

    /**
     * Base query for delegations that are active and whose [starts_at, ends_at]
     * window (inclusive, day granularity) covers the given instant.
     */
    private static function coveringQuery(Carbon $on): Builder
    {
        $day = $on->copy()->startOfDay();

        return static::query()
            ->where('is_active', true)
            ->whereDate('starts_at', '<=', $day)
            ->whereDate('ends_at', '>=', $day)
            ->whereHas('delegator', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('delegate', fn (Builder $query) => $query->where('is_active', true));
    }

    /**
     * Return the user ids that may act for $roleSlug at instant $on by virtue
     * of an active delegation. A delegation grants the role when:
     *   - its role_slug equals $roleSlug, OR
     *   - its role_slug is null AND the delegator actually holds $roleSlug.
     *
     * @return array<int, int>
     */
    public static function activeDelegatesFor(string $roleSlug, Carbon $on): array
    {
        $rows = static::coveringQuery($on)
            ->where(function ($q) use ($roleSlug): void {
                $q->where('role_slug', $roleSlug)
                    ->orWhereNull('role_slug');
            })
            ->with('delegator:id,role_id', 'delegator.role:id,slug')
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            // Both exact-role and blanket delegations are effective only while
            // the delegator still holds the requested role. A role change must
            // revoke delegated authority immediately, without requiring a
            // cleanup job or a manual delegation edit.
            if ($row->delegator && $row->delegator->role?->slug === $roleSlug) {
                $ids[] = (int) $row->delegate_user_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Return the role slugs that $userId may act for at instant $on by virtue
     * of active delegations TO that user.
     *
     * @return array<int, string>
     */
    public static function actsForRoles(int $userId, Carbon $on): array
    {
        $rows = static::coveringQuery($on)
            ->where('delegate_user_id', $userId)
            ->with('delegator:id,role_id', 'delegator.role:id,slug')
            ->get();

        $slugs = [];
        foreach ($rows as $row) {
            $delegatorRole = $row->delegator?->role?->slug;
            if ($row->role_slug !== null && $row->role_slug === $delegatorRole) {
                $slugs[] = (string) $row->role_slug;

                continue;
            }
            // Blanket delegation — inherit whatever single role the delegator holds.
            if ($row->role_slug === null && $delegatorRole !== null) {
                $slugs[] = (string) $delegatorRole;
            }
        }

        return array_values(array_unique($slugs));
    }

    /** @return Collection<int, User> Active delegators who grant this approval permission. */
    public static function delegatorsForPermission(User $delegate, string $permission, ?Carbon $on = null, array $exceptUserIds = []): Collection
    {
        return static::coveringQuery($on ?? now())
            ->where('delegate_user_id', $delegate->id)
            ->with(['delegator.role', 'delegate'])
            ->get()
            ->filter(function (self $delegation) use ($permission, $exceptUserIds): bool {
                $delegator = $delegation->delegator;
                $roleSlug = $delegator?->role?->slug;

                return $delegator !== null
                    && self::mayReadCustomApproval($delegation->delegate, $permission)
                    && ! in_array((int) $delegator->id, $exceptUserIds, true)
                    && $roleSlug !== null
                    && $roleSlug !== 'system_admin'
                    && ($delegation->role_slug === null || $delegation->role_slug === $roleSlug)
                    && $delegator->hasPermission($permission);
            })
            ->map(fn (self $delegation): User => $delegation->delegator)
            ->unique('id')
            ->values();
    }

    /** A delegation grants only this permission, never the delegate's other module permissions. */
    public static function canActForPermission(User $actor, string $permission, array $exceptDelegatorIds = []): bool
    {
        return $actor->hasPermission($permission)
            || static::delegatorsForPermission($actor, $permission, now(), $exceptDelegatorIds)->isNotEmpty();
    }

    public static function hasDifferentPermissionRole(User $actor, string $permission, ?int $otherRoleId, array $exceptDelegatorIds = []): bool
    {
        if ($otherRoleId === null) {
            return true;
        }
        if ($actor->hasPermission($permission) && (int) $actor->role_id !== $otherRoleId) {
            return true;
        }

        return static::delegatorsForPermission($actor, $permission, now(), $exceptDelegatorIds)
            ->contains(static fn (User $delegator): bool => (int) $delegator->role_id !== $otherRoleId);
    }

    /** @return list<int> */
    public static function permissionRoleIds(User $actor, string $permission): array
    {
        $ids = $actor->hasPermission($permission) ? [(int) $actor->role_id] : [];

        return array_values(array_unique([
            ...$ids,
            ...static::delegatorsForPermission($actor, $permission)->pluck('role_id')->map(static fn ($id): int => (int) $id)->all(),
        ]));
    }

    /** @return list<string> */
    public static function delegatedApprovalPermissions(User $delegate): array
    {
        $delegators = static::coveringQuery(now())
            ->where('delegate_user_id', $delegate->id)
            ->with('delegator.role')
            ->get()
            ->filter(static fn (self $row): bool => $row->delegator !== null
                && $row->delegator->role?->slug !== 'system_admin'
                && ($row->role_slug === null || $row->role_slug === $row->delegator->role?->slug))
            ->map(fn (self $row): User => $row->delegator)
            ->filter()
            ->unique('id');

        return array_values(array_filter(self::CUSTOM_APPROVAL_PERMISSIONS, static function (string $permission) use ($delegate, $delegators): bool {
            foreach ($delegators as $delegator) {
                if ($delegator->role?->slug !== 'system_admin'
                    && $delegator->hasPermission($permission)
                    && self::mayReadCustomApproval($delegate, $permission)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private static function mayReadCustomApproval(User $delegate, string $permission): bool
    {
        foreach (self::CUSTOM_APPROVAL_READ_PERMISSIONS[$permission] ?? [] as $readPermission) {
            if ($delegate->hasPermission($readPermission)) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, User> Direct checkers plus active delegates for this approval permission. */
    public static function approvalAudience(string $permission): Collection
    {
        $direct = app(NotificationService::class)->usersWithPermission($permission);
        $rows = static::coveringQuery(now())
            ->with(['delegator.role', 'delegate'])
            ->get();
        $delegates = $rows
            ->filter(function (self $delegation) use ($permission): bool {
                $delegator = $delegation->delegator;
                $roleSlug = $delegator?->role?->slug;

                return $delegator !== null
                    && self::mayReadCustomApproval($delegation->delegate, $permission)
                    && $roleSlug !== null
                    && $roleSlug !== 'system_admin'
                    && ($delegation->role_slug === null || $delegation->role_slug === $roleSlug)
                    && $delegator->hasPermission($permission);
            })
            ->map(fn (self $delegation): ?User => $delegation->delegate)
            ->filter()
            ->unique('id');

        return $direct->merge($delegates)->unique('id')->values();
    }
}
