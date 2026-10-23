<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Enums\RecommendationEventType;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRecommendationEvent;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Verifiers\NcrSlaVerifier;
use App\Modules\DSS\Verifiers\ThreeWayMatchReviewVerifier;
use App\Modules\DSS\Verifiers\WorkOrderInterventionVerifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs registered detectors, persists emissions through the dedupe invariant,
 * verifies missing supported sources, and records per-detector run logs.
 *
 * Isolation: one detector throwing never stops the others; the failure lands
 * in its run log and the outcome's `failed` list, and the command exits
 * non-zero (repo convention: never zeros-mask-failure).
 *
 * Full scans verify findings that disappeared against the source record.
 * Per-detector locks serialize scheduled and manual runs.
 */
class DssRunnerService
{
    private const DEFAULT_VALIDITY_HOURS = 26; // One-hour-runner outage grace; stale cards must not remain actionable.

    public function __construct(
        private readonly DetectorRegistry $registry,
        private readonly DssParameterService $params,
        private readonly RecommendationScorer $scorer,
    ) {}

    /**
     * @param  array{limit?: int, time_budget_ms?: int}  $context
     * @return array{engine: string, detectors: array<string, array{status: string, emitted: int, updated: int, rows_scanned: int, complete: bool, error: ?string}>, failed: list<string>}
     */
    public function run(?string $detectorId = null, array $context = []): array
    {
        if (! $this->params->engineEnabled()) {
            return ['engine' => 'disabled', 'detectors' => [], 'failed' => []];
        }

        $ids = $detectorId !== null ? [$detectorId] : array_keys($this->registry->all());
        $limit = max(1, min(10000, (int) ($context['limit'] ?? $this->params->get(DssParameterService::GLOBAL, 'dss.runner.default_limit', 200))));
        $budgetMs = max(1, min(300000, (int) ($context['time_budget_ms']
            ?? $this->params->get(DssParameterService::GLOBAL, 'dss.runner.time_budget_ms', 30000))));

        $outcome = ['engine' => 'enabled', 'detectors' => [], 'failed' => []];

        foreach ($ids as $id) {
            $outcome['detectors'][$id] = $this->runOne($id, ['limit' => $limit, 'time_budget_ms' => $budgetMs]);

            if ($outcome['detectors'][$id]['status'] === 'failed') {
                $outcome['failed'][] = $id;
            }
        }

        return $outcome;
    }

    /** @return array{status: string, emitted: int, updated: int, rows_scanned: int, complete: bool, error: ?string} */
    private function runOne(string $id, array $context): array
    {
        try {
            return Cache::lock("dss:detector-run:{$id}", 310)->block(
                1,
                fn (): array => $this->runLocked($id, $context),
            );
        } catch (LockTimeoutException) {
            return ['status' => 'skipped', 'emitted' => 0, 'updated' => 0, 'rows_scanned' => 0, 'complete' => false, 'error' => 'Detector already running.'];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'failed', 'emitted' => 0, 'updated' => 0, 'rows_scanned' => 0, 'complete' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
        }
    }

    /** @return array{status: string, emitted: int, updated: int, rows_scanned: int, complete: bool, error: ?string} */
    private function runLocked(string $id, array $context): array
    {
        $startedAt = now();
        $startMs = (int) (microtime(true) * 1000);
        $detector = null;
        $run = null;
        $rowsScanned = 0;

        try {
            $detector = $this->registry->make($id);
            if (! $this->params->detectorEnabled($id)) {
                $this->logRun($detector, $startedAt, $startMs, 'skipped', 0, 0, 'Detector is disabled.');

                return ['status' => 'skipped', 'emitted' => 0, 'updated' => 0, 'rows_scanned' => 0, 'complete' => false, 'error' => null];
            }

            $run = new DssRunLog;
            $run->fill([
                'detector_id' => $detector->id(),
                'detector_version' => $detector->version(),
                'status' => 'running',
                'started_at' => $startedAt,
                'rows_scanned' => 0,
                'emitted' => 0,
            ]);
            $run->save();

            $detectorContext = $context;
            foreach ($detector->defaultParams() as $key => $default) {
                $detectorContext[$key] = $this->params->get($id, (string) $key, $default);
            }
            $budgetMs = (int) $context['time_budget_ms'];
            $scan = DB::transaction(function () use ($detector, $detectorContext, $budgetMs) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::selectOne("select set_config('statement_timeout', ?, true)", [$budgetMs.'ms']);
                }

                return $detector->detect($detectorContext);
            });
            $rowsScanned = $scan->rowsScanned;
            if ((int) (microtime(true) * 1000) - $startMs > $budgetMs) {
                throw new RuntimeException("Detector exceeded its {$budgetMs}ms time budget.");
            }

            $emissions = $scan->emissions;
            $complete = $scan->complete;
            $keys = [];
            foreach ($emissions as $emission) {
                if (isset($keys[$emission->dedupeKey])) {
                    throw new InvalidArgumentException("Detector '{$id}' emitted duplicate dedupe key '{$emission->dedupeKey}'.");
                }
                $keys[$emission->dedupeKey] = true;
            }
            if (count($emissions) > (int) $context['limit']) {
                $emissions = array_slice($emissions, 0, (int) $context['limit']);
                $complete = false;
            }

            $status = $complete ? 'completed' : 'partial';
            $evaluatedAt = now();
            $updated = DB::transaction(function () use ($detector, $emissions, $run, $complete, $keys, $status, $startMs, $rowsScanned, $evaluatedAt): int {
                $updated = 0;
                foreach ($emissions as $emission) {
                    if ($this->persist($detector, $emission, $run, $evaluatedAt)) {
                        $updated++;
                    }
                }

                if ($complete) {
                    $this->verifyMissing($detector, array_slice(array_keys($keys), 0, count($emissions)), $run, $evaluatedAt);
                }

                $run->fill([
                    'status' => $status,
                    'finished_at' => now(),
                    'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
                    'rows_scanned' => $rowsScanned,
                    'emitted' => count($emissions),
                    'error' => null,
                ])->save();

                return $updated;
            });

            return ['status' => $status, 'emitted' => count($emissions), 'updated' => $updated, 'rows_scanned' => $rowsScanned, 'complete' => $complete, 'error' => null];
        } catch (Throwable $e) {
            report($e);
            if ($run !== null) {
                $run->fill([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
                    'rows_scanned' => $rowsScanned,
                    'emitted' => 0,
                    'error' => mb_substr($e->getMessage(), 0, 1000),
                ])->save();
            } elseif ($detector !== null) {
                $this->logRun($detector, $startedAt, $startMs, 'failed', 0, $rowsScanned, mb_substr($e->getMessage(), 0, 1000));
            }

            return ['status' => 'failed', 'emitted' => 0, 'updated' => 0, 'rows_scanned' => $rowsScanned, 'complete' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
        }
    }

    /**
     * Same (detector, dedupe_key) = one open row that updates. Returns true
     * when an existing row was updated, false on insert. Detector run lock
     * serializes writers; row lock protects lifecycle updates.
     */
    private function persist(DetectorInterface $detector, DetectorEmission $emission, DssRunLog $run, \DateTimeInterface $evaluatedAt): bool
    {
        $existing = DssRecommendation::query()
            ->forDetector($detector->id())
            ->where('dedupe_key', $emission->dedupeKey)
            ->whereIn('status', ['open', 'in_progress', 'snoozed'])
            ->lockForUpdate()
            ->first();

        $hasSourceVerifier = $emission->verificationContext !== null
            && in_array($detector->id(), ['dss.work_order_intervention', 'dss.quality_ncr_sla', 'dss.three_way_match_review'], true);
        $attributes = [
            'detector_version' => $detector->version(),
            'domain' => $detector->domain(),
            'type' => $emission->type,
            'subject_type' => $emission->subjectType,
            'subject_id' => $emission->subjectId,
            'severity' => $emission->severity,
            'priority_score' => $this->scorer->score($emission),
            'impact_estimate' => $emission->impactEstimate,
            'impact_unit' => $emission->impactUnit,
            'impact_currency' => $emission->impactCurrency,
            'impact_metric' => $emission->impactMetric,
            'confidence' => $emission->confidence,
            'data_sufficiency' => $emission->dataSufficiency,
            'rationale' => $emission->rationale,
            'action' => $emission->action,
            'expires_at' => $emission->expiresAt ?? now()->addHours(self::DEFAULT_VALIDITY_HOURS),
            'owner_permissions' => array_values(array_unique($emission->ownerPermissions ?? $detector->ownerPermissions())),
            'owner_role_slugs' => $emission->ownerRoleSlugs === null ? null : array_values(array_unique($emission->ownerRoleSlugs)),
            'decision_code' => $emission->decisionCode,
            'policy_version' => $emission->policyVersion,
            'source_references' => $emission->sourceReferences,
            'verification_context' => $emission->verificationContext,
            'first_detected_at' => $existing?->first_detected_at ?? $evaluatedAt,
            'last_evaluated_at' => $evaluatedAt,
            'last_evaluated_run_id' => $run->id,
            'source_observed_at' => $emission->sourceObservedAt,
            'business_due_at' => $emission->businessDueAt,
            'verification_status' => $hasSourceVerifier ? VerificationResult::STILL_OPEN : VerificationResult::UNAVAILABLE,
            'resolution_evidence' => null,
        ];

        if ($existing === null) {
            $previous = DssRecommendation::query()->forDetector($detector->id())
                ->where('dedupe_key', $emission->dedupeKey)->latest('id')->first();
            $recommendation = new DssRecommendation;
            $recommendation->fill([...$attributes,
                'detector_id' => $detector->id(),
                'dedupe_key' => $emission->dedupeKey,
                'predecessor_id' => $previous?->status === 'resolved' ? $previous->id : null,
            ]);
            $recommendation->save();

            return false;
        }

        $existing->fill($attributes);
        $existing->save();

        return true;
    }

    /** @param list<string> $currentKeys */
    private function verifyMissing(DetectorInterface $detector, array $currentKeys, DssRunLog $run, \DateTimeInterface $evaluatedAt): void
    {
        $query = DssRecommendation::query()
            ->forDetector($detector->id())
            ->whereIn('status', ['open', 'in_progress', 'snoozed']);
        if ($currentKeys !== []) {
            $query->whereNotIn('dedupe_key', $currentKeys);
        }

        $query->orderBy('id')->chunkById(100, function ($rows) use ($run, $evaluatedAt): void {
            foreach ($rows as $row) {
                $recommendation = DssRecommendation::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($recommendation === null || ! in_array($recommendation->status, ['open', 'in_progress', 'snoozed'], true)) {
                    continue;
                }

                $result = match ($recommendation->detector_id) {
                    'dss.work_order_intervention' => app(WorkOrderInterventionVerifier::class)->verify($recommendation),
                    'dss.quality_ncr_sla' => app(NcrSlaVerifier::class)->verify($recommendation),
                    'dss.three_way_match_review' => app(ThreeWayMatchReviewVerifier::class)->verify($recommendation),
                    default => new VerificationResult(VerificationResult::UNAVAILABLE),
                };
                $recommendation->verification_status = $result->status;

                if ($result->status !== VerificationResult::UNAVAILABLE) {
                    $recommendation->last_evaluated_at = $evaluatedAt;
                    $recommendation->last_evaluated_run_id = $run->id;
                }

                if ($result->status === VerificationResult::VERIFIED_CLEAR) {
                    $from = $recommendation->status;
                    $recommendation->status = 'resolved';
                    $recommendation->source_observed_at = $result->observedAt;
                    $recommendation->resolution_evidence = [
                        'source_condition' => $recommendation->decision_code,
                        'verifier' => match ($recommendation->detector_id) {
                            'dss.work_order_intervention' => 'work_order_status',
                            'dss.quality_ncr_sla' => 'ncr_response_sla',
                            'dss.three_way_match_review' => 'three_way_match_saved_state',
                            default => null,
                        },
                        'run_reference' => $run->hash_id,
                        'proof' => $result->proof,
                        'observed_at' => $result->observedAt?->format(DATE_ATOM),
                    ];
                    $recommendation->save();
                    $event = new DssRecommendationEvent;
                    $event->fill([
                        'recommendation_id' => $recommendation->id,
                        'event' => RecommendationEventType::Resolved->value,
                        'from_status' => $from,
                        'to_status' => 'resolved',
                        'reason' => 'The source record confirms this condition has cleared.',
                        'metadata' => ['source' => 'source_verification'],
                    ]);
                    $event->save();

                    continue;
                }

                $recommendation->save();
            }
        });
    }

    private function logRun(
        DetectorInterface $detector,
        \DateTimeInterface $startedAt,
        int $startMs,
        string $status,
        int $emitted,
        int $rowsScanned,
        ?string $error,
    ): void {
        $finished = now();

        $log = new DssRunLog;
        $log->fill([
            'detector_id' => $detector->id(),
            'detector_version' => $detector->version(),
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $finished,
            'duration_ms' => (int) (microtime(true) * 1000) - $startMs,
            'rows_scanned' => $rowsScanned,
            'emitted' => $emitted,
            'error' => $error,
        ]);
        $log->save();
    }
}
