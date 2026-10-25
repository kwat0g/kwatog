<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\DSS\Support\MasterHygieneScope;

/**
 * D27 — master-data hygiene score. The detector keeps one recommendation per
 * gap; its detail endpoint lists the full current source scope without making
 * one card per affected record.
 */
class MasterHygieneDetector implements DetectorInterface
{
    public function __construct(private readonly MasterHygieneScope $scope) {}

    public function id(): string
    {
        return 'dss.master_hygiene';
    }

    public function domain(): string
    {
        return 'platform';
    }

    public function ownerPermissions(): array
    {
        return ['admin.settings.manage'];
    }

    public function version(): string
    {
        return 'v3';
    }

    public function defaultParams(): array
    {
        return ['max_evidence_per_gap' => 10, 'critical_count' => 50];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $maxEvidence = max(1, min(50, (int) ($context['max_evidence_per_gap'] ?? $this->defaultParams()['max_evidence_per_gap'])));
        $criticalAt = max(1, min(100000, (int) ($context['critical_count'] ?? $this->defaultParams()['critical_count'])));
        $emissions = [];
        $rowsScanned = 0;

        foreach ($this->scope->definitions() as $key => $definition) {
            $query = $this->scope->query($key);
            $count = (clone $query)->count();
            $rowsScanned += $count;
            if ($count === 0) {
                continue;
            }

            $emissions[] = new DetectorEmission(
                type: 'master_hygiene',
                severity: $count >= $criticalAt ? 'critical' : 'warning',
                dedupeKey: "hygiene:{$key}",
                rationale: [
                    'rule' => 'master-data completeness scan',
                    'gap' => $key,
                    'affected_count' => $count,
                    'evidence' => (clone $query)->orderBy('id')->limit($maxEvidence)->pluck($definition['evidence_column'])->all(),
                    'why_it_matters' => $definition['why'],
                ],
                confidence: 1.0,
                action: ['route' => $definition['route'], 'label' => 'Open master data →'],
                ownerPermissions: [$definition['permission']],
                decisionCode: "master_data_gap.{$key}",
                verificationContext: ['gap' => $key],
            );
        }

        return new DetectorScanResult($emissions, $rowsScanned);
    }
}
