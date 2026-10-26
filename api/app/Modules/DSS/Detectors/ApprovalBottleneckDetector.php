<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Common\Models\ApprovalRecord;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;

/**
 * D25 — approval bottleneck view. The escalation sweep already reminds and
 * escalates per record; this detector answers the manager's question instead:
 * which roles are sitting on stale approvals, and how stale is the oldest?
 *
 * One aggregated emission per role_slug (never one row per record). Threshold
 * defaults mirror the canonical approvals.reminder_hours (24) and
 * approvals.escalation_hours (48) settings (see SettingsSeeder and migration
 * 0290); pass reminder_hours/escalation_hours via $context to override.
 * Deterministic counts → confidence 1.0. Read-only.
 */
class ApprovalBottleneckDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'dss.approval_bottleneck';
    }

    public function domain(): string
    {
        return 'platform';
    }

    public function ownerPermissions(): array
    {
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return ['reminder_hours' => 24, 'escalation_hours' => 48, 'max_evidence_per_role' => 10];
    }

    /** @return list<DetectorEmission> */
    public function detect(array $context = []): DetectorScanResult
    {
        $defaults = $this->defaultParams();
        $reminderHours = max(1, (int) ($context['reminder_hours'] ?? $defaults['reminder_hours']));
        $escalationHours = max(1, (int) ($context['escalation_hours'] ?? $defaults['escalation_hours']));
        $maxEvidence = max(1, (int) ($context['max_evidence'] ?? $defaults['max_evidence_per_role']));
        $cutoff = now()->subHours($reminderHours);

        /** @var array<string, array{count: int, oldest_hours: int, evidence: list<string>}> $byRole */
        $byRole = [];
        $rowsScanned = 0;

        ApprovalRecord::query()
            ->where('action', 'pending')
            ->where('is_current', true)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($records) use (&$byRole, &$rowsScanned, $maxEvidence): void {
                foreach ($records as $record) {
                    $rowsScanned++;
                    $role = (string) $record->role_slug;
                    $ageHours = (int) abs(now()->diffInHours($record->created_at));

                    if (! isset($byRole[$role])) {
                        $byRole[$role] = ['count' => 0, 'oldest_hours' => 0, 'evidence' => []];
                    }

                    $byRole[$role]['count']++;
                    $byRole[$role]['oldest_hours'] = max($byRole[$role]['oldest_hours'], $ageHours);

                    if (count($byRole[$role]['evidence']) < $maxEvidence) {
                        $byRole[$role]['evidence'][] =
                            "{$record->approvable_type}#{$record->approvable_id} step {$record->step_order}";
                    }
                }
            });

        $emissions = [];
        foreach ($byRole as $role => $stuck) {
            $emissions[] = new DetectorEmission(
                type: 'approval_bottleneck',
                severity: $stuck['oldest_hours'] >= $escalationHours ? 'critical' : 'warning',
                dedupeKey: "approval-bottleneck:{$role}",
                rationale: [
                    'rule' => 'stuck approval scan',
                    'role_slug' => $role,
                    'affected_count' => $stuck['count'],
                    'oldest_pending_hours' => $stuck['oldest_hours'],
                    'thresholds' => [
                        'reminder_hours' => $reminderHours,
                        'escalation_hours' => $escalationHours,
                    ],
                    'evidence' => $stuck['evidence'],
                    'why_it_matters' => 'Pending approvals past the reminder threshold stall their documents; past escalation age they need reassignment.',
                ],
                confidence: 1.0,
                action: ['route' => '/approvals', 'label' => 'Open approval queue →'],
            );
        }

        return new DetectorScanResult($emissions, $rowsScanned);
    }
}
