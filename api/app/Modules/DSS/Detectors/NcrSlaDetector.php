<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrSlaService;

/** D13 — NCR response SLA, using the same clock and thresholds as escalation policy. */
class NcrSlaDetector implements DetectorInterface
{
    public function __construct(private readonly NcrSlaService $ncrSla) {}

    public function id(): string { return 'dss.quality_ncr_sla'; }
    public function domain(): string { return 'quality'; }
    public function ownerPermissions(): array { return ['quality.ncr.view']; }
    public function version(): string { return 'v1'; }
    public function defaultParams(): array { return []; }

    public function detect(array $context = []): DetectorScanResult
    {
        $limit = max(1, (int) ($context['limit'] ?? 200));
        $emissions = [];
        $rowsScanned = 0;
        $complete = true;

        foreach (NcrSeverity::cases() as $severity) {
            $hours = $this->ncrSla->hoursFor($severity);
            $rows = $this->ncrSla->overdueQuery($severity)
                ->where('escalation_level', '<', 3)
                ->whereDoesntHave('actions', fn ($query) => $query->where('action_type', NcrActionType::Corrective->value))
                ->orderByRaw('COALESCE(last_escalated_at, created_at)')
                ->limit($limit - count($emissions) + 1)
                ->get(['id', 'ncr_number', 'severity', 'status', 'escalation_level', 'last_escalated_at', 'created_at']);

            $rowsScanned += $rows->count();
            if ($rows->count() > $limit - count($emissions)) {
                $complete = false;
                $rows = $rows->take($limit - count($emissions));
            }

            foreach ($rows as $row) {
                $clock = $this->ncrSla->clockStart($row) ?? now();
                $waitHours = $clock->diffInHours(now(), true);
                $emissions[] = new DetectorEmission(
                    type: 'ncr_sla_breach',
                    severity: match ($severity) {
                        NcrSeverity::Critical, NcrSeverity::High => 'critical',
                        NcrSeverity::Medium => 'warning',
                        NcrSeverity::Low => 'info',
                    },
                    dedupeKey: "ncr-sla:{$row->id}",
                    rationale: [
                        'rule' => 'open NCR exceeded its response SLA',
                        'ncr_number' => $row->ncr_number,
                        'ncr_severity' => $severity->value,
                        'status' => $row->status->value,
                        'hours_since_escalation' => $waitHours,
                        'sla_hours' => $hours,
                        'escalation_level' => (int) $row->escalation_level,
                    ],
                    subjectType: NonConformanceReport::class,
                    subjectId: (int) $row->id,
                    confidence: 1.0,
                    action: ['route' => '/quality/ncrs/'.$row->hash_id, 'label' => 'Review NCR →'],
                    decisionCode: 'ncr_sla_breach',
                    policyVersion: $this->version(),
                    sourceReferences: [[
                        'source_kind' => 'ncr',
                        'public_id' => (string) $row->hash_id,
                        'display_reference' => (string) $row->ncr_number,
                        'destination' => '/quality/ncrs/'.$row->hash_id,
                    ]],
                    verificationContext: [
                        'source_id' => (int) $row->id,
                        'severity' => $severity->value,
                        'sla_hours' => $hours,
                        'source_clock' => $clock->toIso8601String(),
                        'source_clock_rule' => 'COALESCE(last_escalated_at, created_at)',
                    ],
                    sourceObservedAt: $clock,
                    businessDueAt: $this->ncrSla->dueAt($row),
                );
            }
        }

        return new DetectorScanResult($emissions, $rowsScanned, $complete);
    }
}
