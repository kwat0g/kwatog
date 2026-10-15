<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Common\Services\SettingsService;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NonConformanceReport;

/**
 * D13 — NCR SLA breach follow-up. The escalation sweep already reminds and
 * escalates per record; this detector answers the QC manager's question:
 * which severities are sitting past SLA, how many, and how stale is the oldest?
 *
 * Overdue rule mirrors NcrEscalationService::advanceOne exactly: the clock
 * starts at last_escalated_at ?: created_at, and the bar is the canonical
 * quality.ncr.sla_{severity}_hours setting (fail-closed via requiredInt, like
 * the service). Deliberate difference: NCRs WITH a corrective action still
 * count while open past SLA — an unverified fix is still an open risk.
 *
 * One aggregated emission per severity (never one row per NCR). Read-only.
 */
class NcrSlaBreachDetector implements DetectorInterface
{
    public const ID = 'dss.ncr_sla_breach';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function domain(): string
    {
        return 'quality';
    }

    public function ownerPermissions(): array
    {
        return ['quality.ncr.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return ['max_evidence_per_severity' => 10];
    }

    /** @return list<DetectorEmission> */
    public function detect(array $context = []): array
    {
        $maxEvidence = max(1, (int) ($context['max_evidence'] ?? $this->defaultParams()['max_evidence_per_severity']));

        $emissions = [];

        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $slaHours = $this->settings->requiredInt("quality.ncr.sla_{$severity}_hours", 1);
            $cutoff = now()->subHours($slaHours);

            $count = 0;
            $oldestOverdueHours = 0;
            $evidence = [];

            NonConformanceReport::query()
                ->where('severity', $severity)
                ->whereIn('status', [NcrStatus::Open->value, NcrStatus::InProgress->value])
                ->whereRaw('COALESCE(last_escalated_at, created_at) < ?', [$cutoff])
                ->orderBy('id')
                ->chunkById(200, function ($ncrs) use (&$count, &$oldestOverdueHours, &$evidence, $maxEvidence): void {
                    foreach ($ncrs as $ncr) {
                        $clock = $ncr->last_escalated_at ?: $ncr->created_at;
                        $count++;
                        $oldestOverdueHours = max(
                            $oldestOverdueHours,
                            (int) abs(now()->diffInHours($clock))
                        );

                        if (count($evidence) < $maxEvidence) {
                            $evidence[] = (string) $ncr->ncr_number;
                        }
                    }
                });

            if ($count === 0) {
                continue;
            }

            $emissions[] = new DetectorEmission(
                type: 'ncr_sla_breach',
                severity: $severity === 'critical' ? 'critical' : 'warning',
                dedupeKey: "ncr-sla:{$severity}",
                rationale: [
                    'rule' => 'ncr sla breach scan',
                    'severity' => $severity,
                    'affected_count' => $count,
                    'oldest_overdue_hours' => $oldestOverdueHours,
                    'sla_hours' => $slaHours,
                    'evidence' => $evidence,
                    'why_it_matters' => 'Open NCRs past their severity SLA stall containment, corrective action, and customer response.',
                ],
                confidence: 1.0,
                action: ['route' => '/quality/ncrs', 'label' => 'Open NCR queue →'],
            );
        }

        return $emissions;
    }
}
