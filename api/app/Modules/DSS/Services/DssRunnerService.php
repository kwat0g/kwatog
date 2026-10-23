<?php

declare(strict_types=1);

namespace App\Modules\DSS\Services;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Models\DssRunLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs registered detectors, persists emissions through the dedupe invariant,
 * and records per-detector run logs. Pipeline stage: detectors → scorer →
 * deduplicator (here) → publisher (W0-3 display filtering + W1 notifications).
 *
 * Isolation: one detector throwing never stops the others; the failure lands
 * in its run log and the outcome's `failed` list, and the command exits
 * non-zero (repo convention: never zeros-mask-failure).
 *
 * Modes (shadow/advisory/assisted) affect DISPLAY only (W0-3 filters the user
 * list by current mode). The runner persists in every mode so shadow outputs
 * can be judged against reality before promotion.
 */
class DssRunnerService
{
    public function __construct(
        private readonly DetectorRegistry $registry,
        private readonly DssParameterService $params,
        private readonly RecommendationScorer $scorer,
    ) {}

    /**
     * @param  array{limit?: int, time_budget_ms?: int}  $context
     * @return array{engine: string, detectors: array<string, array{status: string, emitted: int, updated: int, error: ?string}>, failed: list<string>}
     */
    public function run(?string $detectorId = null, array $context = []): array
    {
        if (! $this->params->engineEnabled()) {
            return ['engine' => 'disabled', 'detectors' => [], 'failed' => []];
        }

        $ids = $detectorId !== null ? [$detectorId] : array_keys($this->registry->all());
        $limit = $context['limit'] ?? $this->params->get(DssParameterService::GLOBAL, 'dss.runner.default_limit', 200);
        $budgetMs = $context['time_budget_ms']
            ?? $this->params->get(DssParameterService::GLOBAL, 'dss.runner.time_budget_ms', 30000);

        $outcome = ['engine' => 'enabled', 'detectors' => [], 'failed' => []];

        foreach ($ids as $id) {
            $outcome['detectors'][$id] = $this->runOne($id, ['limit' => $limit, 'time_budget_ms' => $budgetMs]);

            if ($outcome['detectors'][$id]['status'] === 'failed') {
                $outcome['failed'][] = $id;
            }
        }

        return $outcome;
    }

    /** @return array{status: string, emitted: int, updated: int, error: ?string} */
    private function runOne(string $id, array $context): array
    {
        $detector = $this->registry->make($id);
        $startedAt = now();
        $startMs = (int) (microtime(true) * 1000);

        try {
            if (! $this->params->detectorEnabled($id)) {
                return ['status' => 'skipped', 'emitted' => 0, 'updated' => 0, 'error' => null];
            }

            $emissions = $detector->detect($context);
            $updated = 0;

            foreach ($emissions as $emission) {
                if ($this->persist($detector, $emission)) {
                    $updated++;
                }
            }

            $this->logRun($detector, $startedAt, $startMs, 'completed', count($emissions), null);

            return ['status' => 'completed', 'emitted' => count($emissions), 'updated' => $updated, 'error' => null];
        } catch (Throwable $e) {
            report($e);
            $this->logRun($detector, $startedAt, $startMs, 'failed', 0, mb_substr($e->getMessage(), 0, 1000));

            return ['status' => 'failed', 'emitted' => 0, 'updated' => 0, 'error' => mb_substr($e->getMessage(), 0, 500)];
        }
    }

    /**
     * Same (detector, dedupe_key) = one open row that updates. Returns true
     * when an existing row was updated, false on insert. Row-locked so two
     * workers cannot double-insert past the partial unique index (the index is
     * the backstop; the lock is the fast path).
     */
    private function persist(DetectorInterface $detector, DetectorEmission $emission): bool
    {
        return DB::transaction(function () use ($detector, $emission): bool {
            $existing = DssRecommendation::query()
                ->forDetector($detector->id())
                ->where('dedupe_key', $emission->dedupeKey)
                ->whereIn('status', ['open', 'snoozed'])
                ->lockForUpdate()
                ->first();

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
                'confidence' => $emission->confidence,
                'data_sufficiency' => $emission->dataSufficiency,
                'rationale' => $emission->rationale,
                'action' => $emission->action,
                'expires_at' => $emission->expiresAt,
            ];

            if ($existing === null) {
                $recommendation = new DssRecommendation;
                $recommendation->fill([...$attributes,
                    'detector_id' => $detector->id(),
                    'dedupe_key' => $emission->dedupeKey,
                ]);
                // Single save → one row; status defaults to `open` and is
                // never mass-assigned (W0-1 hardening test).
                $recommendation->save();

                return false;
            }

            $existing->fill($attributes);
            $existing->save();

            return true;
        });
    }

    private function logRun(
        DetectorInterface $detector,
        \DateTimeInterface $startedAt,
        int $startMs,
        string $status,
        int $emitted,
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
            // rows_scanned stays 0 until detectors report scans (v1
            // limitation, documented): only `emitted` is measured today.
            'rows_scanned' => 0,
            'emitted' => $emitted,
            'error' => $error,
        ]);
        $log->save();
    }
}
