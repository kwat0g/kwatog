<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Enums\RecommendationEventType;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRecommendationEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Recommendation lifecycle: open → accepted/dismissed/snoozed → executed,
 * plus viewed/outcome events. Every transition writes exactly one event row
 * (the audit trail: shown → viewed → decision → action → outcome).
 *
 * Visibility rule (backend-enforced, never trusted to the SPA): a user sees a
 * recommendation only when they hold `dss.view` (route gate) AND at least one
 * of the detector's owner permissions AND the detector's current mode is
 * advisory/assisted. Shadow-detector rows exist for judgment against reality
 * but never reach the user list (admin console in W0-6 sees them).
 *
 * Expiry and snooze-return are LAZY (query-time), not cron-driven: rows past
 * expires_at vanish from the list, and snoozed rows resurface when
 * snoozed_until passes. No sweeper to stall, no stale-state window.
 */
class RecommendationService
{
    public function __construct(
        private readonly DetectorRegistry $registry,
        private readonly DssParameterService $params,
    ) {}

    public function canSee(User $user, string $detectorId): bool
    {
        if (! $this->registry->has($detectorId)) {
            return false;
        }

        if (! in_array($this->params->detectorMode($detectorId), ['advisory', 'assisted'], true)) {
            return false;
        }

        foreach ($this->registry->make($detectorId)->ownerPermissions() as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $query = DssRecommendation::query()->where(function ($query): void {
            $query->where('status', 'open')
                ->orWhere(function ($query): void {
                    $query->where('status', 'snoozed')->where('snoozed_until', '<=', now());
                });
        })->where(function ($query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });

        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['domain'])) {
            $query->where('domain', $filters['domain']);
        }

        if (! empty($filters['detector_id'])) {
            $query->where('detector_id', $filters['detector_id']);
        }

        // Newest and highest-priority first; severity rank applied in PHP would
        // break pagination, so priority_score (which already embeds severity)
        // is the single sort key.
        $query->orderByDesc('priority_score')->orderByDesc('id');

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);
        $page = $query->paginate($perPage);

        // Detector scoping in PHP: owner-permission sets are per-detector, not
        // per-row, so SQL cannot express "user holds ≥1 of this set" without a
        // join against the permission graph. Pages are small (≤100); filter
        // the collection and keep the paginator shape.
        $page->setCollection(
            $page->getCollection()->filter(
                fn (DssRecommendation $rec): bool => $this->canSee($user, $rec->detector_id) && $this->audienceAllows($user, $rec)
            )->values()
        );

        return $page;
    }

    public function show(User $user, DssRecommendation $recommendation): DssRecommendation
    {
        $this->assertVisible($user, $recommendation);

        DB::transaction(function () use ($user, $recommendation): void {
            $viewedToday = DssRecommendationEvent::query()
                ->where('recommendation_id', $recommendation->id)
                ->where('event', RecommendationEventType::Viewed->value)
                ->where('actor_id', $user->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->exists();

            // One viewed row per user per day — the trail stays readable when
            // a recommendation is opened repeatedly.
            if (! $viewedToday) {
                $this->recordEvent($recommendation, RecommendationEventType::Viewed, null, null, $user->id, null, null);
            }
        });

        return $recommendation;
    }

    /**
     * @param  array{reason?: string, snoozed_until?: string, outcome?: array}  $data
     */
    public function act(User $user, DssRecommendation $recommendation, string $action, array $data = []): DssRecommendation
    {
        $this->assertVisible($user, $recommendation);

        return DB::transaction(function () use ($user, $recommendation, $action, $data): DssRecommendation {
            $recommendation = DssRecommendation::query()
                ->whereKey($recommendation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $recommendation->status;

            $recommendation = match ($action) {
                'accept' => $this->applyAccept($recommendation, $user, $from),
                'dismiss' => $this->applyDismiss($recommendation, $user, $from, $data),
                'snooze' => $this->applySnooze($recommendation, $user, $from, $data),
                'execute' => $this->applyExecute($recommendation, $user, $from),
                default => throw new BusinessRuleException("Unknown recommendation action '{$action}'."),
            };

            return $recommendation;
        });
    }

    /** Measured result recorded later; status-neutral, decided rows only. */
    public function recordOutcome(User $user, DssRecommendation $recommendation, array $outcome): DssRecommendation
    {
        $this->assertVisible($user, $recommendation);

        return DB::transaction(function () use ($user, $recommendation, $outcome): DssRecommendation {
            $recommendation = DssRecommendation::query()
                ->whereKey($recommendation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($recommendation->status, ['accepted', 'executed', 'dismissed'], true)) {
                throw new BusinessRuleException('Outcomes can only be recorded on decided recommendations.');
            }

            $this->recordEvent($recommendation, RecommendationEventType::Outcome, $recommendation->status, $recommendation->status, $user->id, null, ['outcome' => $outcome]);

            return $recommendation;
        });
    }

    private function assertVisible(User $user, DssRecommendation $recommendation): void
    {
        if (! $this->canSee($user, $recommendation->detector_id)
            || ! $this->audienceAllows($user, $recommendation)) {
            abort(403, 'You do not have access to this recommendation.');
        }
    }

    /**
     * Opt-in per-row audience gate. Detectors that aggregate across audiences
     * (e.g. stuck documents) label each emission with audience_roles; rows
     * without the label stay visible to everyone the detector admits. Same
     * rule as ChainBottleneckController: own role slug plus delegated roles.
     */
    private function audienceAllows(User $user, DssRecommendation $recommendation): bool
    {
        $roles = $recommendation->rationale['audience_roles'] ?? null;
        if (! is_array($roles) || $roles === []) {
            return true;
        }

        $mine = array_values(array_filter(
            array_merge([$user->role?->slug], ApprovalDelegation::actsForRoles($user->id, now())),
            static fn ($slug): bool => is_string($slug) && $slug !== '',
        ));

        return count(array_intersect($roles, $mine)) > 0;
    }

    private function applyAccept(DssRecommendation $recommendation, User $user, string $from): DssRecommendation
    {
        if ($from !== 'open' && ! ($from === 'snoozed' && $recommendation->snoozed_until <= now())) {
            throw new BusinessRuleException('Only open recommendations can be accepted.');
        }

        $recommendation->status = 'accepted';
        $recommendation->decided_by = $user->id;
        $recommendation->decided_at = now();
        $recommendation->save();
        $this->recordEvent($recommendation, RecommendationEventType::Accepted, $from, 'accepted', $user->id, null, null);

        return $recommendation;
    }

    /** @param array{reason?: string} $data */
    private function applyDismiss(DssRecommendation $recommendation, User $user, string $from, array $data): DssRecommendation
    {
        if (! in_array($from, ['open', 'snoozed'], true)) {
            throw new BusinessRuleException('Only open recommendations can be dismissed.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new BusinessRuleException('A dismissal reason is required.');
        }

        $recommendation->status = 'dismissed';
        $recommendation->decided_by = $user->id;
        $recommendation->decided_at = now();
        $recommendation->dismiss_reason = $reason;
        $recommendation->save();
        $this->recordEvent($recommendation, RecommendationEventType::Dismissed, $from, 'dismissed', $user->id, $reason, null);

        return $recommendation;
    }

    /** @param array{snoozed_until?: string, reason?: string} $data */
    private function applySnooze(DssRecommendation $recommendation, User $user, string $from, array $data): DssRecommendation
    {
        if ($from !== 'open') {
            throw new BusinessRuleException('Only open recommendations can be snoozed.');
        }

        $until = isset($data['snoozed_until']) ? strtotime((string) $data['snoozed_until']) : false;
        if ($until === false || $until <= time()) {
            throw new BusinessRuleException('Snooze requires a future return date.');
        }

        $recommendation->status = 'snoozed';
        $recommendation->snoozed_until = date('Y-m-d H:i:s', $until);
        $recommendation->save();
        $this->recordEvent($recommendation, RecommendationEventType::Snoozed, $from, 'snoozed', $user->id, $data['reason'] ?? null, null);

        return $recommendation;
    }

    private function applyExecute(DssRecommendation $recommendation, User $user, string $from): DssRecommendation
    {
        if ($from !== 'accepted') {
            throw new BusinessRuleException('Only accepted recommendations can be marked executed.');
        }

        $recommendation->status = 'executed';
        $recommendation->save();
        $this->recordEvent($recommendation, RecommendationEventType::Executed, $from, 'executed', $user->id, null, null);

        return $recommendation;
    }

    private function recordEvent(
        DssRecommendation $recommendation,
        RecommendationEventType $event,
        ?string $from,
        ?string $to,
        ?int $actorId,
        ?string $reason,
        ?array $metadata,
    ): void {
        $row = new DssRecommendationEvent;
        $row->fill([
            'recommendation_id' => $recommendation->id,
            'event' => $event->value,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
        $row->save();
    }
}
