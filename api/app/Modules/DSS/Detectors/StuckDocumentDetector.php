<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Common\Services\ChainBottleneckService;
use App\Common\Services\SettingsService;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;

/**
 * D26 — stuck documents. Reuses ChainBottleneckService (the chain widget's
 * own engine: same thresholds, same rows) and adds what the widget lacks: a
 * prioritized, lifecycle-managed recommendation per bottleneck key.
 *
 * One aggregated emission per bottleneck key (never one row per document).
 * Dynamic `next_approver` audiences stay out: pending approvals by role are
 * the D25 detector's job. Per-row audience enforcement lives in
 * RecommendationService (rationale audience_roles); this detector only labels.
 * Deterministic counts → confidence 1.0. Read-only.
 */
class StuckDocumentDetector implements DetectorInterface
{
    public const ID = 'dss.stuck_documents';

    public function __construct(
        private readonly ChainBottleneckService $bottlenecks,
        private readonly SettingsService $settings,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function domain(): string
    {
        return 'platform';
    }

    public function ownerPermissions(): array
    {
        // Reachability only. Row-level access is enforced per audience_role
        // in RecommendationService — a warehouse user never sees finance rows.
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return ['critical_multiple' => 2, 'max_evidence_per_key' => 10];
    }

    /** @return list<DetectorEmission> */
    public function detect(array $context = []): DetectorScanResult
    {
        $criticalMultiple = max(1, (int) ($context['critical_multiple'] ?? $this->defaultParams()['critical_multiple']));
        $maxEvidence = max(1, (int) ($context['max_evidence'] ?? $this->defaultParams()['max_evidence_per_key']));
        $policies = $this->settings->get('dashboard.chain_bottlenecks', []);
        $resultLimit = $this->settings->get('dashboard.chain_bottlenecks.result_limit', 50);

        $emissions = [];
        $rowsScanned = 0;

        foreach ($this->bottlenecks->detectAll() as $key => $rows) {
            $rowsScanned += count($rows);
            if ($rows === []) {
                continue;
            }

            $audience = (string) ($rows[0]['audience'] ?? '');
            if ($audience === '' || $audience === 'next_approver') {
                continue;
            }

            $thresholdHours = (int) (is_array($policies) ? ($policies[$key]['hours'] ?? 0) : 0);
            $oldestStuck = 0;
            $evidence = [];
            foreach ($rows as $row) {
                $oldestStuck = max($oldestStuck, (int) ($row['hours_stuck'] ?? 0));
                if (count($evidence) < $maxEvidence && ($row['doc_number'] ?? '') !== '') {
                    $evidence[] = (string) $row['doc_number'];
                }
            }

            $emissions[] = new DetectorEmission(
                type: 'stuck_document',
                severity: ($thresholdHours > 0 && $oldestStuck >= $criticalMultiple * $thresholdHours) ? 'critical' : 'warning',
                dedupeKey: "stuck:{$key}",
                rationale: [
                    'rule' => 'stuck document scan',
                    'bottleneck_key' => $key,
                    'label' => (string) ($rows[0]['label'] ?? $key),
                    'audience_roles' => [$audience],
                    'affected_count' => count($rows),
                    'capped_at_result_limit' => is_int($resultLimit) && count($rows) >= $resultLimit,
                    'oldest_stuck_hours' => $oldestStuck,
                    'threshold_hours' => $thresholdHours,
                    'evidence' => $evidence,
                    'why_it_matters' => 'Documents stuck past their chain threshold stall every downstream step.',
                ],
                confidence: 1.0,
                action: ['route' => '/chains', 'label' => 'Open chain tracker →'],
            );
        }

        return new DetectorScanResult($emissions, $rowsScanned);
    }
}
