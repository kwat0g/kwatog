<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Models\CustomerComplaint;
use Illuminate\Support\Facades\Log;

/**
 * T3.2.B — 8D SLA escalator.
 *
 * Three tiered windows tracked on customer_complaints:
 *   d3_due_at        = created_at + 48h  (containment)
 *   d4_due_at        = created_at + 7d   (root cause)
 *   finalize_due_at  = created_at + 30d  (8D finalised)
 *
 * Each tier fires once per complaint. The `sla_alert_levels` jsonb
 * accumulates {'d3','d4','finalize'} so re-runs are idempotent.
 *
 * Skips complaints in terminal status (closed, cancelled).
 */
class Complaint8dEscalationService
{
    private const TIERS = [
        'd3' => [
            'due_field'        => 'd3_due_at',
            'block_when_field' => 'd3_containment',
            'subject'          => '8D D3 containment overdue',
        ],
        'd4' => [
            'due_field'        => 'd4_due_at',
            'block_when_field' => 'd4_root_cause',
            'subject'          => '8D D4 root cause overdue',
        ],
        'finalize' => [
            'due_field'        => 'finalize_due_at',
            'block_when_field' => null, // gate is "finalized_at is null"
            'subject'          => '8D finalisation overdue',
        ],
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @return array{d3:int, d4:int, finalize:int}
     */
    public function run(): array
    {
        $counts = ['d3' => 0, 'd4' => 0, 'finalize' => 0];
        $now = now();

        $candidates = CustomerComplaint::query()
            ->with('eightDReport', 'assignee')
            ->whereNotIn('status', [
                ComplaintStatus::Closed->value,
                ComplaintStatus::Cancelled->value,
            ])
            ->where(function ($q) use ($now) {
                $q->where('d3_due_at', '<', $now)
                  ->orWhere('d4_due_at', '<', $now)
                  ->orWhere('finalize_due_at', '<', $now);
            })
            ->get();

        $recipients = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['quality', 'qc_inspector']))
            ->where('is_active', true)
            ->get();

        foreach ($candidates as $c) {
            try {
                $fired = (array) ($c->sla_alert_levels ?? []);
                $report = $c->eightDReport;
                $changed = false;

                foreach (self::TIERS as $key => $cfg) {
                    if (in_array($key, $fired, true)) continue;

                    $dueAt = $c->{$cfg['due_field']};
                    if (! $dueAt || $now->lessThanOrEqualTo($dueAt)) continue;

                    // Gate: tier only fires when the corresponding D-field is
                    // still empty (or, for finalize, when not finalised yet).
                    if ($key === 'finalize') {
                        if ($report && $report->finalized_at) continue;
                    } else {
                        $val = $report ? trim((string) $report->{$cfg['block_when_field']}) : '';
                        if ($val !== '') continue;
                    }

                    // Notify quality + qc_inspector pool, plus assignee if any.
                    $audience = $recipients->all();
                    if ($c->assignee && $c->assignee->is_active) {
                        $audience[] = $c->assignee;
                    }
                    foreach ($audience as $u) {
                        $this->notifications->send($u, '8d.sla', [
                            'title'   => $cfg['subject'],
                            'message' => "Complaint {$c->complaint_number} has missed the {$key} SLA window. Review the 8D and act now.",
                            'link_to' => "/crm/complaints/{$c->hash_id}",
                        ]);
                    }

                    $fired[] = $key;
                    $counts[$key]++;
                    $changed = true;
                }

                if ($changed) {
                    $c->forceFill(['sla_alert_levels' => array_values(array_unique($fired))])->save();
                }
            } catch (\Throwable $e) {
                Log::warning('Complaint8dEscalationService: tier evaluation failed', [
                    'complaint_id' => $c->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }
}
