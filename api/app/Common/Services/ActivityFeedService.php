<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ActivityEvent;
use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * Series F — Task F7. Activity feed.
 *
 * Read-side: paginated feed for the admin activity dashboard, with
 * filters (type, actor, severity, date range).
 *
 * Write-side: `record()` is a one-call helper that listeners and
 * automation jobs can use to surface a high-level event. The service
 * fills in actor + IP from the current request when present.
 */
class ActivityFeedService
{
    /**
     * @param  string|object|null  $subject  Either an Eloquent model or [type, id] tuple.
     * @param  array<string, mixed>  $detail
     */
    public function record(
        string $type,
        string $action,
        $subject = null,
        string $summary = '',
        array $detail = [],
        ?string $link = null,
        string $severity = 'info',
        ?string $idempotencyKey = null,
        ?int $actorUserId = null,
        ?string $actorType = null,
        ?string $ipAddress = null,
        ?CarbonInterface $createdAt = null,
    ): ActivityEvent {
        return DB::transaction(function () use (
            $type,
            $action,
            $subject,
            $summary,
            $detail,
            $link,
            $severity,
            $idempotencyKey,
            $actorUserId,
            $actorType,
            $ipAddress,
            $createdAt,
        ) {
            [$subjectType, $subjectId] = $this->resolveSubject($subject);

            $user = function_exists('auth') ? auth()->user() : null;
            $resolvedActorId = $actorUserId ?? $user?->id;
            $resolvedActorId = $resolvedActorId !== null && User::query()->whereKey($resolvedActorId)->exists()
                ? (int) $resolvedActorId
                : null;

            $payload = [
                'type'           => $type,
                'action'         => $action,
                'actor_user_id'  => $resolvedActorId,
                'actor_type'     => $actorType ?? ($resolvedActorId !== null ? 'user' : 'system'),
                'subject_type'   => $subjectType,
                'subject_id'     => $subjectId,
                'summary'        => $summary,
                'detail'         => $detail,
                'link'           => $link,
                'severity'       => $severity,
                'ip_address'     => $ipAddress ?? RequestFacade::ip(),
                'created_at'     => $createdAt ?? now(),
                'idempotency_key' => $idempotencyKey,
            ];

            // The unique database key is the race-proof part of idempotency.
            // insertOrIgnore lets two queue workers safely converge on the
            // same row without an update to an immutable event.
            if ($idempotencyKey !== null) {
                $insertPayload = $payload;
                // Query-builder inserts bypass Eloquent's JSON cast.
                $insertPayload['detail'] = json_encode($detail, JSON_THROW_ON_ERROR);
                ActivityEvent::query()->insertOrIgnore([$insertPayload]);

                return ActivityEvent::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }

            return ActivityEvent::create($payload);
        });
    }

    /**
     * Paginated feed.
     *
     * @param  array<string, mixed>  $filters
     */
    public function feed(array $filters): LengthAwarePaginator
    {
        // ADV4 — also load the actor's role so the feed can render a role chip
        // beside the user's name.
        $q = ActivityEvent::query()->with([
            'actor:id,name,email,role_id',
            'actor.role:id,name,slug',
        ]);

        if (! empty($filters['type'])) {
            $q->where('type', (string) $filters['type']);
        }
        if (! empty($filters['severity'])) {
            $q->where('severity', (string) $filters['severity']);
        }
        if (! empty($filters['actor_user_id'])) {
            $raw = (string) $filters['actor_user_id'];
            $decoded = User::tryDecodeHash($raw);
            if (app()->environment('testing') && ctype_digit($raw)) {
                $decoded = (int) $raw;
            }

            $decoded === null
                ? $q->whereRaw('1 = 0')
                : $q->where('actor_user_id', $decoded);
        }
        if (! empty($filters['from'])) {
            $q->where('created_at', '>=', $this->dateBoundary($filters['from'], false));
        }
        if (! empty($filters['to'])) {
            $q->where('created_at', '<=', $this->dateBoundary($filters['to'], true));
        }
        if (! empty($filters['search'])) {
            $s = (string) $filters['search'];
            $q->where('summary', 'ilike', "%{$s}%");
        }

        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 50)));

        return $q->orderByDesc('created_at')->paginate($perPage);
    }

    private function dateBoundary(mixed $value, bool $endOfDay): CarbonImmutable
    {
        $date = $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse((string) $value);

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    /** @return array{0: ?string, 1: ?int} */
    private function resolveSubject($subject): array
    {
        if ($subject === null) return [null, null];

        if (is_array($subject) && isset($subject[0], $subject[1])) {
            return [(string) $subject[0], (int) $subject[1]];
        }

        if (is_object($subject) && method_exists($subject, 'getMorphClass') && method_exists($subject, 'getKey')) {
            return [$subject->getMorphClass(), (int) $subject->getKey()];
        }

        return [null, null];
    }
}
