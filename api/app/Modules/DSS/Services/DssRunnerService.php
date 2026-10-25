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
use App\Modules\DSS\Verifiers\MasterHygieneVerifier;
use App\Modules\DSS\Verifiers\NcrSlaVerifier;
use App\Modules\DSS\Verifiers\ThreeWayMatchReviewVerifier;
use App\Modules\DSS\Verifiers\WorkOrderInterventionVerifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
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

    /** @var \WeakMap<Connection, array{deadline_ns: int, budget_ms: int, caller_timeout: ?string, caller_timeout_ms: int}>|null */
    private static ?\WeakMap $activeDeadlines = null;

    /** @var \WeakMap<Connection, bool>|null */
    private static ?\WeakMap $deadlineHooks = null;

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

    public function failTimedOutRun(string $detectorId, string $reason): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $run = DssRunLog::query()
            ->where('detector_id', $detectorId)
            ->where('status', 'running')
            ->latest('id')
            ->first();
        if ($run === null) {
            return;
        }

        DB::transaction(function () use ($run, $reason): void {
            $lockedRun = DssRunLog::query()->whereKey($run->id)->lockForUpdate()->first();
            if ($lockedRun?->status !== 'running') {
                return;
            }

            $lockedRun->fill([
                'status' => 'failed',
                'finished_at' => now(),
                'duration_ms' => max(0, (int) $lockedRun->started_at->diffInMilliseconds(now(), true)),
                'emitted' => 0,
                'error' => mb_substr($reason, 0, 1000),
            ])->save();
        });
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
        $startNs = hrtime(true);
        $startMs = (int) ($startNs / 1_000_000);
        $budgetMs = (int) $context['time_budget_ms'];
        $deadlineNs = $startNs + $budgetMs * 1_000_000;
        $connection = DB::connection();
        $detector = null;
        $run = null;
        $rowsScanned = 0;

        try {
            $this->activateDeadline($connection, $deadlineNs, $budgetMs);
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
            $scan = $this->transactionWithDeadline(function () use ($detector, $detectorContext, $deadlineNs, $budgetMs) {
                $result = $detector->detect($detectorContext);
                $this->assertWithinDeadline($deadlineNs, $budgetMs);

                return $result;
            }, $deadlineNs, $budgetMs);
            $rowsScanned = $scan->rowsScanned;
            $this->assertWithinDeadline($deadlineNs, $budgetMs);

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
            $updated = $this->transactionWithDeadline(function () use ($detector, $emissions, $run, $complete, $keys, $status, $startMs, $rowsScanned, $evaluatedAt, $deadlineNs, $budgetMs): int {
                $lockedRun = DssRunLog::query()->whereKey($run->id)->lockForUpdate()->first();
                if ($lockedRun === null || $lockedRun->status !== 'running') {
                    throw new RuntimeException('Detector run was interrupted before persistence.');
                }

                $updated = 0;
                foreach ($emissions as $emission) {
                    $this->setStatementTimeout($deadlineNs, $budgetMs);
                    if ($this->persist($detector, $emission, $run, $evaluatedAt)) {
                        $updated++;
                    }
                }

                if ($complete) {
                    $this->verifyMissing($detector, array_slice(array_keys($keys), 0, count($emissions)), $run, $evaluatedAt, $deadlineNs, $budgetMs);
                }

                $this->assertWithinDeadline($deadlineNs, $budgetMs);
                $this->setStatementTimeout($deadlineNs, $budgetMs);
                $lockedRun->fill([
                    'status' => $status,
                    'finished_at' => now(),
                    'duration_ms' => (int) (hrtime(true) / 1_000_000) - $startMs,
                    'rows_scanned' => $rowsScanned,
                    'emitted' => count($emissions),
                    'error' => null,
                ])->save();

                $this->assertWithinDeadline($deadlineNs, $budgetMs);

                return $updated;
            }, $deadlineNs, $budgetMs);

            return ['status' => $status, 'emitted' => count($emissions), 'updated' => $updated, 'rows_scanned' => $rowsScanned, 'complete' => $complete, 'error' => null];
        } catch (Throwable $e) {
            $this->deactivateDeadline($connection);
            report($e);
            if ($run !== null) {
                try {
                    DB::transaction(function () use ($run, $startMs, $rowsScanned, $e): void {
                        $lockedRun = DssRunLog::query()->whereKey($run->id)->lockForUpdate()->first();
                        if ($lockedRun?->status === 'running') {
                            $lockedRun->fill([
                                'status' => 'failed',
                                'finished_at' => now(),
                                'duration_ms' => (int) (hrtime(true) / 1_000_000) - $startMs,
                                'rows_scanned' => $rowsScanned,
                                'emitted' => 0,
                                'error' => mb_substr($e->getMessage(), 0, 1000),
                            ])->save();
                        }
                    });
                } catch (Throwable $logError) {
                    report($logError);
                }
            } elseif ($detector !== null) {
                try {
                    $this->logRun($detector, $startedAt, $startMs, 'failed', 0, $rowsScanned, mb_substr($e->getMessage(), 0, 1000));
                } catch (Throwable $logError) {
                    report($logError);
                }
            }

            return ['status' => 'failed', 'emitted' => 0, 'updated' => 0, 'rows_scanned' => $rowsScanned, 'complete' => false, 'error' => mb_substr($e->getMessage(), 0, 500)];
        } finally {
            $this->deactivateDeadline($connection);
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
            && in_array($detector->id(), ['dss.work_order_intervention', 'dss.quality_ncr_sla', 'dss.three_way_match_review', 'dss.master_hygiene'], true);
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
    private function verifyMissing(DetectorInterface $detector, array $currentKeys, DssRunLog $run, \DateTimeInterface $evaluatedAt, int $deadlineNs, int $budgetMs): void
    {
        $query = DssRecommendation::query()
            ->forDetector($detector->id())
            ->whereIn('status', ['open', 'in_progress', 'snoozed']);
        if ($currentKeys !== []) {
            $query->whereNotIn('dedupe_key', $currentKeys);
        }

        $lastId = 0;
        do {
            $this->setStatementTimeout($deadlineNs, $budgetMs);
            $rows = (clone $query)->where('id', '>', $lastId)->orderBy('id')->limit(100)->get();
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $this->setStatementTimeout($deadlineNs, $budgetMs);
                $recommendation = DssRecommendation::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($recommendation === null || ! in_array($recommendation->status, ['open', 'in_progress', 'snoozed'], true)) {
                    continue;
                }

                $result = match ($recommendation->detector_id) {
                    'dss.work_order_intervention' => app(WorkOrderInterventionVerifier::class)->verify($recommendation),
                    'dss.quality_ncr_sla' => app(NcrSlaVerifier::class)->verify($recommendation),
                    'dss.three_way_match_review' => app(ThreeWayMatchReviewVerifier::class)->verify($recommendation),
                    'dss.master_hygiene' => app(MasterHygieneVerifier::class)->verify($recommendation),
                    default => new VerificationResult(VerificationResult::UNAVAILABLE),
                };
                $this->assertWithinDeadline($deadlineNs, $budgetMs);
                $recommendation->verification_status = $result->status;

                if ($result->status !== VerificationResult::UNAVAILABLE) {
                    $recommendation->last_evaluated_at = $evaluatedAt;
                    $recommendation->last_evaluated_run_id = $run->id;
                }

                if ($result->status === VerificationResult::VERIFIED_CLEAR) {
                    $from = $recommendation->status;
                    $sourceCondition = $recommendation->decision_code;
                    if ($sourceCondition === null && $recommendation->detector_id === 'dss.master_hygiene') {
                        $gap = $recommendation->rationale['gap'] ?? null;
                        $sourceCondition = is_string($gap) ? "master_data_gap.{$gap}" : null;
                    }
                    $recommendation->status = 'resolved';
                    $recommendation->source_observed_at = $result->observedAt;
                    $recommendation->resolution_evidence = [
                        'source_condition' => $sourceCondition,
                        'verifier' => match ($recommendation->detector_id) {
                            'dss.work_order_intervention' => 'work_order_status',
                            'dss.quality_ncr_sla' => 'ncr_response_sla',
                            'dss.three_way_match_review' => 'three_way_match_current',
                            'dss.master_hygiene' => 'master_hygiene_source',
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
            $lastId = (int) $rows->last()->id;
        } while ($rows->count() === 100);
    }

    private function remainingMs(int $deadlineNs, int $budgetMs): int
    {
        $remaining = (int) (($deadlineNs - hrtime(true)) / 1_000_000);
        if ($remaining < 1) {
            throw new RuntimeException("Detector exceeded its {$budgetMs}ms time budget.");
        }

        return $remaining;
    }

    private function assertWithinDeadline(int $deadlineNs, int $budgetMs): void
    {
        $this->remainingMs($deadlineNs, $budgetMs);
    }

    private function activateDeadline(Connection $connection, int $deadlineNs, int $budgetMs): void
    {
        self::$activeDeadlines ??= new \WeakMap;
        self::$deadlineHooks ??= new \WeakMap;
        $callerTimeout = null;
        $callerTimeoutMs = 0;
        if ($connection->getDriverName() === 'pgsql') {
            $setting = $connection->getPdo()
                ->query("select current_setting('statement_timeout') as timeout, setting as timeout_ms from pg_settings where name = 'statement_timeout'")
                ->fetch(\PDO::FETCH_ASSOC);
            $callerTimeout = (string) $setting['timeout'];
            $callerTimeoutMs = (int) $setting['timeout_ms'];
        }

        if (! isset(self::$deadlineHooks[$connection])) {
            $connection->beforeExecuting(static function (string $query, array $bindings, Connection $connection): void {
                $deadline = self::$activeDeadlines[$connection] ?? null;
                if ($deadline === null) {
                    return;
                }

                $remainingNs = $deadline['deadline_ns'] - hrtime(true);
                if ($remainingNs < 1_000_000) {
                    throw new RuntimeException("Detector exceeded its {$deadline['budget_ms']}ms time budget.");
                }

                if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() < 1) {
                    return;
                }

                $remainingMs = (int) ($remainingNs / 1_000_000);
                if ($deadline['caller_timeout_ms'] > 0) {
                    $remainingMs = min($remainingMs, $deadline['caller_timeout_ms']);
                }
                $statement = $connection->getPdo()->prepare("select set_config('statement_timeout', ?, true)");
                $statement->execute([$remainingMs.'ms']);
            });
            self::$deadlineHooks[$connection] = true;
        }

        self::$activeDeadlines[$connection] = [
            'deadline_ns' => $deadlineNs,
            'budget_ms' => $budgetMs,
            'caller_timeout' => $callerTimeout,
            'caller_timeout_ms' => $callerTimeoutMs,
        ];
    }

    private function deactivateDeadline(Connection $connection): void
    {
        if (self::$activeDeadlines !== null && isset(self::$activeDeadlines[$connection])) {
            $callerTimeout = self::$activeDeadlines[$connection]['caller_timeout'];
            if ($callerTimeout !== null && $connection->transactionLevel() > 0) {
                $statement = $connection->getPdo()->prepare("select set_config('statement_timeout', ?, true)");
                $statement->execute([$callerTimeout]);
            }
            unset(self::$activeDeadlines[$connection]);
        }
    }

    private function setStatementTimeout(int $deadlineNs, int $budgetMs): ?string
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertWithinDeadline($deadlineNs, $budgetMs);

            return null;
        }

        $remainingMs = $this->remainingMs($deadlineNs, $budgetMs);
        $settings = DB::selectOne("select current_setting('statement_timeout') as timeout, setting as timeout_ms from pg_settings where name = 'statement_timeout'");
        $previous = $settings->timeout;
        $callerTimeoutMs = (int) $settings->timeout_ms;
        $boundedMs = $callerTimeoutMs > 0 ? min($remainingMs, $callerTimeoutMs) : $remainingMs;
        DB::selectOne("select set_config('statement_timeout', ?, true)", [$boundedMs.'ms']);

        return $previous;
    }

    private function transactionWithDeadline(callable $callback, int $deadlineNs, int $budgetMs): mixed
    {
        $previousTimeout = null;
        if (DB::getDriverName() === 'pgsql') {
            $activeDeadline = self::$activeDeadlines[DB::connection()] ?? null;
            $previousTimeout = $activeDeadline['caller_timeout'] ?? (string) DB::connection()->getPdo()
                ->query("select current_setting('statement_timeout')")
                ->fetchColumn();
        }

        try {
            $result = DB::transaction(function () use ($callback, $deadlineNs, $budgetMs, &$previousTimeout): mixed {
                $this->setStatementTimeout($deadlineNs, $budgetMs);

                return $callback();
            });
        } catch (Throwable $e) {
            $this->restoreStatementTimeoutAfterTransaction($previousTimeout, $e);
            throw $e;
        }

        $this->restoreStatementTimeoutAfterTransaction($previousTimeout);

        return $result;
    }

    private function restoreStatementTimeoutAfterTransaction(?string $previousTimeout, ?Throwable $originalError = null): void
    {
        try {
            $this->restoreStatementTimeout($previousTimeout);
        } catch (Throwable $restoreError) {
            if ($originalError === null) {
                throw $restoreError;
            }

            report($restoreError);
        }
    }

    private function restoreStatementTimeout(?string $previousTimeout): void
    {
        if ($previousTimeout !== null && DB::getDriverName() === 'pgsql') {
            $statement = DB::connection()->getPdo()->prepare("select set_config('statement_timeout', ?, true)");
            $statement->execute([$previousTimeout]);
        }
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
            'duration_ms' => (int) (hrtime(true) / 1_000_000) - $startMs,
            'rows_scanned' => $rowsScanned,
            'emitted' => $emitted,
            'error' => $error,
        ]);
        $log->save();
    }
}
