<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\DSS\Enums\RecommendationEventType;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRecommendationEvent;
use App\Modules\DSS\Models\DssRunLog;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recommendation lifecycle: open → in_progress or legacy acknowledgement,
 * plus snoozed, resolved, expired, viewed, and outcome events.
 *
 * Visibility rule (backend-enforced, never trusted to the SPA): a user sees a
 * recommendation only when they hold `dss.view` (route gate) AND at least one
 * of the detector's owner permissions AND the detector's current mode is
 * advisory. Shadow-detector rows exist for judgment against reality
 * but never reach the user list (admin console in W0-6 sees them).
 *
 * Expiry and snooze-return are lazy at read time, with a daily audit sweep.
 * Active rows require an association with a successful detector evaluation.
 */
class RecommendationService
{
    public function __construct(
        private readonly DetectorRegistry $registry,
        private readonly DssParameterService $params,
    ) {}

    public function canSee(User $user, string $detectorId, ?DssRecommendation $recommendation = null): bool
    {
        if (! $user->hasPermission('dss.view') || ! $this->detectorIsActive($detectorId)) {
            return false;
        }

        if ($recommendation !== null && in_array($recommendation->status, ['open', 'in_progress', 'snoozed'], true)) {
            if (! $this->hasSuccessfulEvaluation($recommendation)) {
                return false;
            }
        }

        $ownerRoles = $recommendation?->owner_role_slugs;
        if (is_array($ownerRoles) && $user->role?->slug !== 'system_admin'
            && array_intersect($ownerRoles, $this->userRoleSlugs($user)) === []) {
            return false;
        }

        $permissions = $recommendation?->owner_permissions ?? $this->registry->make($detectorId)->ownerPermissions();
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $base = $this->queryFor($user, $filters);
        $this->validateAvailableFilters($base, $filters);
        $query = $this->filterQuery(clone $base, $filters);
        $sort = $filters['sort'] ?? 'priority_score';
        $direction = $filters['direction'] ?? 'desc';
        if ($sort === 'priority_score' && $direction === 'desc') {
            $query->orderByRaw('priority_score DESC NULLS LAST');
        } else {
            $query->orderBy($sort, $direction);
        }
        $query->orderBy('id', 'desc');

        return $query->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function summary(User $user, array $filters = []): array
    {
        $base = $this->queryFor($user, $filters);
        $this->validateAvailableFilters($base, $filters);
        $counts = $this->filterQuery(clone $base, $filters)->select('severity', DB::raw('count(*) as aggregate'))
            ->groupBy('severity')->pluck('aggregate', 'severity');

        return [
            'total' => (int) $counts->sum(),
            'by_severity' => [
                'critical' => (int) ($counts['critical'] ?? 0),
                'warning' => (int) ($counts['warning'] ?? 0),
                'info' => (int) ($counts['info'] ?? 0),
            ],
        ];
    }

    public function options(User $user): array
    {
        $query = $this->activeQuery($user);
        $domains = (clone $query)->whereNotNull('domain')->distinct()->orderBy('domain')->pluck('domain')->all();
        $roles = $this->roleOptionsQuery($query)->distinct()->orderBy('slug')->pluck('slug')->filter(fn ($slug) => $slug !== '')->values()->all();
        $labels = Role::query()->whereIn('slug', $roles)->pluck('name', 'slug');
        $domainLabels = [
            'cross_module' => 'Connected workflows', 'approvals' => 'Approvals', 'production' => 'Production',
            'accounting' => 'Finance', 'quality' => 'Quality', 'maintenance' => 'Maintenance',
            'payroll' => 'Payroll', 'human_resources' => 'People and training', 'platform' => 'Master data',
        ];

        return [
            'domains' => array_map(fn (string $value): array => ['value' => $value, 'label' => $domainLabels[$value] ?? 'Business operations'], $domains),
            'owner_roles' => array_map(fn (string $value): array => ['value' => $value, 'label' => $labels[$value] ?? 'Owner not recorded'], $roles),
            'statuses' => [
                ['value' => 'open', 'label' => 'New'],
                ['value' => 'in_progress', 'label' => 'In progress'],
                ['value' => 'snoozed', 'label' => 'Snoozed'],
                ['value' => 'accepted', 'label' => 'Accepted'],
                ['value' => 'executed', 'label' => 'Executed'],
                ['value' => 'dismissed', 'label' => 'Dismissed'],
                ['value' => 'resolved', 'label' => 'Resolved'],
                ['value' => 'expired', 'label' => 'Expired'],
            ],
            'severities' => [['value' => 'critical', 'label' => 'Critical'], ['value' => 'warning', 'label' => 'Warning'], ['value' => 'info', 'label' => 'Information']],
        ];
    }

    private function activeQuery(User $user): Builder
    {
        return $this->queryFor($user, []);
    }

    private function queryFor(User $user, array $filters): Builder
    {
        $query = $this->visibleQuery($user);
        $status = $filters['status'] ?? null;
        $historyStatuses = ['accepted', 'executed', 'dismissed', 'resolved', 'expired'];

        if (in_array($status, $historyStatuses, true)) {
            return $query->where('status', $status);
        }

        $query->whereIn('status', ['open', 'in_progress', 'snoozed'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereNotNull('last_evaluated_at')
            ->whereExists(fn ($runs) => $runs->selectRaw('1')->from('dss_run_logs')
                ->whereColumn('dss_run_logs.id', 'dss_recommendations.last_evaluated_run_id')
                ->whereColumn('dss_run_logs.detector_id', 'dss_recommendations.detector_id')
                ->whereIn('dss_run_logs.status', ['completed', 'partial']));

        if ($status !== null) {
            $query->where('status', $status);
        } else {
            $query->where(fn ($q) => $q->whereIn('status', ['open', 'in_progress'])
                ->orWhere(fn ($snoozed) => $snoozed->where('status', 'snoozed')->where('snoozed_until', '<=', now())));
        }

        return $query;
    }

    private function visibleQuery(User $user): Builder
    {
        $visibleDetectorIds = array_values(array_filter(array_keys($this->registry->all()),
            fn (string $id): bool => $user->hasPermission('dss.view') && $this->detectorIsActive($id)));
        $query = DssRecommendation::query()->whereIn('detector_id', $visibleDetectorIds);

        if ($user->role?->slug !== 'system_admin') {
            $permissions = $user->permission_slugs;
            $legacyVisibleDetectorIds = array_values(array_filter($visibleDetectorIds, fn (string $id): bool => array_intersect($this->registry->make($id)->ownerPermissions(), $permissions) !== []));
            $query->where(function ($q) use ($permissions, $legacyVisibleDetectorIds): void {
                foreach ($permissions as $permission) {
                    $q->orWhereJsonContains('owner_permissions', [$permission]);
                }
                if ($legacyVisibleDetectorIds !== []) {
                    $q->orWhere(fn ($legacy) => $legacy->whereNull('owner_permissions')->whereIn('detector_id', $legacyVisibleDetectorIds));
                }
            });
            $query->where(function ($q) use ($user): void {
                $q->whereNull('owner_role_slugs');
                foreach ($this->userRoleSlugs($user) as $slug) {
                    $q->orWhereJsonContains('owner_role_slugs', [$slug]);
                }
            });
        }

        return $query;
    }

    private function hasSuccessfulEvaluation(DssRecommendation $recommendation): bool
    {
        return $recommendation->last_evaluated_at !== null
            && $recommendation->last_evaluated_run_id !== null
            && DssRunLog::query()->whereKey($recommendation->last_evaluated_run_id)
                ->where('detector_id', $recommendation->detector_id)
                ->whereIn('status', ['completed', 'partial'])
                ->exists();
    }

    private function filterQuery(Builder $query, array $filters): Builder
    {
        foreach (['severity', 'domain', 'detector_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['owner_role'])) {
            $query->whereJsonContains('owner_role_slugs', [$filters['owner_role']]);
        }
        if (! empty($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        return $query;
    }

    private function validateAvailableFilters(Builder $base, array $filters): void
    {
        $domains = (clone $base)->whereNotNull('domain')->distinct()->pluck('domain')->all();
        $detectors = (clone $base)->distinct()->pluck('detector_id')->all();
        $roles = $this->roleOptionsQuery($base)->distinct()->pluck('slug')->all();
        foreach (['domain' => $domains, 'owner_role' => $roles, 'detector_id' => $detectors] as $key => $values) {
            if (isset($filters[$key]) && ! in_array($filters[$key], $values, true)) {
                throw ValidationException::withMessages([$key => 'The selected value is unavailable.']);
            }
        }
    }

    private function roleOptionsQuery(Builder $base): \Illuminate\Database\Query\Builder
    {
        return DB::query()->fromSub((clone $base)->selectRaw("jsonb_array_elements(CASE WHEN jsonb_typeof(owner_role_slugs) = 'array' THEN owner_role_slugs ELSE '[]'::jsonb END) AS role_value"), 'owner_roles')
            ->whereRaw("jsonb_typeof(role_value) = 'string'")->selectRaw("role_value #>> '{}' AS slug");
    }

    private function applySearch(Builder $query, string $search): void
    {
        $pattern = SearchOperator::contains($search);
        $query->where(function ($q) use ($pattern): void {
            foreach (['customer', 'work_order', 'ncr_number', 'training', 'rule'] as $key) {
                $q->orWhereRaw("CASE WHEN jsonb_typeof(rationale->'{$key}') = 'string' THEN rationale->>'{$key}' END ".SearchOperator::like().' ?', [$pattern]);
            }
            $q->orWhereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(rationale->'sample_documents') = 'array' THEN rationale->'sample_documents' ELSE '[]'::jsonb END) AS sample_document(value) WHERE jsonb_typeof(value) = 'object' AND jsonb_typeof(value->'document') = 'string' AND value->>'document' ".SearchOperator::like().' ?)', [$pattern]);
        });
    }

    public function expirePastValidity(): int
    {
        return $this->expireMatching(
            DssRecommendation::query()->whereNotNull('expires_at'),
            static fn (DssRecommendation $recommendation): bool => $recommendation->expires_at !== null && $recommendation->expires_at->lte(now()),
            'Recommendation validity window ended.',
            ['source' => 'scheduled_expiry'],
        );
    }

    public function expireForPause(?string $detectorId = null): int
    {
        $query = DssRecommendation::query();
        if ($detectorId !== null) {
            $query->where('detector_id', $detectorId);
        }

        return $this->expireMatching(
            $query,
            static fn (DssRecommendation $recommendation): bool => true,
            'DSS configuration changed; previous recommendation invalidated.',
            ['source' => 'configuration_change'],
        );
    }

    private function expireMatching(Builder $query, Closure $eligible, string $reason, array $metadata): int
    {
        $expired = 0;
        $query
            ->whereIn('status', ['open', 'in_progress', 'snoozed'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$expired, $eligible, $reason, $metadata): void {
                foreach ($rows as $row) {
                    $didExpire = DB::transaction(function () use ($row, $eligible, $reason, $metadata): bool {
                        $recommendation = DssRecommendation::query()->whereKey($row->id)->lockForUpdate()->first();
                        if ($recommendation === null || ! in_array($recommendation->status, ['open', 'in_progress', 'snoozed'], true)
                            || ! $eligible($recommendation)) {
                            return false;
                        }

                        $from = $recommendation->status;
                        $recommendation->status = 'expired';
                        $recommendation->save();
                        $this->recordEvent(
                            $recommendation,
                            RecommendationEventType::Expired,
                            $from,
                            'expired',
                            null,
                            $reason,
                            $metadata,
                        );

                        return true;
                    });

                    $expired += (int) $didExpire;
                }
            });

        return $expired;
    }

    public function show(User $user, DssRecommendation $recommendation): DssRecommendation
    {
        $this->assertVisible($user, $recommendation);
        if ($this->expireIfPast($recommendation)) {
            $recommendation->status = 'expired';
        }

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

        $result = DB::transaction(function () use ($user, $recommendation, $action, $data): array {
            $recommendation = DssRecommendation::query()
                ->whereKey($recommendation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertVisible($user, $recommendation);

            if (in_array($recommendation->status, ['open', 'in_progress', 'snoozed'], true)
                && $recommendation->expires_at !== null
                && $recommendation->expires_at->lte(now())) {
                $from = $recommendation->status;
                $recommendation->status = 'expired';
                $recommendation->save();
                $this->recordEvent(
                    $recommendation,
                    RecommendationEventType::Expired,
                    $from,
                    'expired',
                    null,
                    'Recommendation validity window ended.',
                    ['source' => 'lazy_expiry'],
                );

                return ['recommendation' => $recommendation, 'expired' => true];
            }

            $from = $recommendation->status;

            $recommendation = match ($action) {
                'accept' => $this->applyAccept($recommendation, $user, $from),
                'dismiss' => $this->applyDismiss($recommendation, $user, $from, $data),
                'snooze' => $this->applySnooze($recommendation, $user, $from, $data),
                'execute' => $this->applyExecute($recommendation, $user, $from),
                'start_follow_up' => $this->applyStartFollowUp($recommendation, $user, $from),
                default => throw new BusinessRuleException("Unknown recommendation action '{$action}'."),
            };

            return ['recommendation' => $recommendation, 'expired' => false];
        });

        if ($result['expired']) {
            throw new BusinessRuleException('This recommendation expired and can no longer be acted on.');
        }

        return $result['recommendation'];
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

            $this->assertVisible($user, $recommendation);

            if (! in_array($recommendation->status, ['accepted', 'executed', 'dismissed'], true)) {
                throw new BusinessRuleException('Outcomes can only be recorded on decided recommendations.');
            }

            $this->recordEvent($recommendation, RecommendationEventType::Outcome, $recommendation->status, $recommendation->status, $user->id, null, ['outcome' => $outcome]);

            return $recommendation;
        });
    }

    private function assertVisible(User $user, DssRecommendation $recommendation): void
    {
        if (! $this->canSee($user, $recommendation->detector_id, $recommendation)) {
            abort(403, 'You do not have access to this recommendation.');
        }
    }

    private function detectorIsActive(string $detectorId): bool
    {
        return $this->registry->has($detectorId)
            && $this->params->engineEnabled()
            && $this->params->detectorEnabled($detectorId)
            && $this->params->detectorMode($detectorId) === 'advisory';
    }

    /** @return list<string> */
    private function userRoleSlugs(User $user): array
    {
        return array_values(array_unique(array_filter([
            $user->role?->slug,
            ...ApprovalDelegation::actsForRoles((int) $user->id, now()),
        ], 'is_string')));
    }

    private function expireIfPast(DssRecommendation $recommendation): bool
    {
        return DB::transaction(function () use ($recommendation): bool {
            $row = DssRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->first();
            if ($row === null || ! in_array($row->status, ['open', 'in_progress', 'snoozed'], true)
                || $row->expires_at === null || $row->expires_at->gt(now())) {
                return false;
            }

            $from = $row->status;
            $row->status = 'expired';
            $row->save();
            $this->recordEvent(
                $row,
                RecommendationEventType::Expired,
                $from,
                'expired',
                null,
                'Recommendation validity window ended.',
                ['source' => 'lazy_expiry'],
            );

            return true;
        });
    }

    private function applyAccept(DssRecommendation $recommendation, User $user, string $from): DssRecommendation
    {
        if (! in_array($from, ['open', 'in_progress'], true)
            && ! ($from === 'snoozed' && $recommendation->snoozed_until !== null && $recommendation->snoozed_until->lte(now()))) {
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
        if (! in_array($from, ['open', 'in_progress', 'snoozed'], true)) {
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
        if (! in_array($from, ['open', 'in_progress'], true)) {
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

    private function applyStartFollowUp(DssRecommendation $recommendation, User $user, string $from): DssRecommendation
    {
        if ($from === 'in_progress') {
            return $recommendation;
        }
        if ($from !== 'open') {
            throw new BusinessRuleException('Only new recommendations can start follow-up.');
        }

        $recommendation->status = 'in_progress';
        $recommendation->save();
        $this->recordEvent($recommendation, RecommendationEventType::Started, $from, 'in_progress', $user->id, null, null);

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
