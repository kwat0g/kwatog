<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OGAMI-013 — Approval delegation.
 *
 * Lets a user (delegator) nominate another user (delegate) to act on their
 * behalf for approval steps during a date window. A null `role_slug` means the
 * delegate may act for EVERY role the delegator currently holds; a concrete
 * slug scopes the delegation to a single role.
 *
 * @property int         $delegator_user_id
 * @property int         $delegate_user_id
 * @property string|null $role_slug
 * @property \Carbon\Carbon $starts_at
 * @property \Carbon\Carbon $ends_at
 * @property bool        $is_active
 */
class ApprovalDelegation extends Model
{
    use HasHashId;
    use HasAuditLog;

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
        'ends_at'   => 'date',
        'is_active' => 'boolean',
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
    private static function coveringQuery(Carbon $on): \Illuminate\Database\Eloquent\Builder
    {
        $day = $on->copy()->startOfDay();

        return static::query()
            ->where('is_active', true)
            ->whereDate('starts_at', '<=', $day)
            ->whereDate('ends_at', '>=', $day);
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
            ->with('delegator:id,role_id')
            ->get();

        $ids = [];
        foreach ($rows as $row) {
            if ($row->role_slug === $roleSlug) {
                $ids[] = (int) $row->delegate_user_id;
                continue;
            }
            // role_slug null — only honour it if the delegator genuinely holds
            // the requested role, so a blanket delegation never escalates a
            // delegate beyond the delegator's own authority.
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
            ->with('delegator:id,role_id')
            ->get();

        $slugs = [];
        foreach ($rows as $row) {
            if ($row->role_slug !== null) {
                $slugs[] = (string) $row->role_slug;
                continue;
            }
            // Blanket delegation — inherit whatever single role the delegator holds.
            $slug = $row->delegator?->role?->slug;
            if ($slug !== null) {
                $slugs[] = (string) $slug;
            }
        }

        return array_values(array_unique($slugs));
    }
}
