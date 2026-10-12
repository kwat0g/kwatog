<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics;

use App\Common\Services\ChainListenerRecoveryService;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\Quality\Enums\NcrStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only executive signals built from existing operational records. */
final class ExecutiveWidgetAnalytics
{
    public function __construct(
        private readonly ChainListenerRecoveryService $chainRecovery,
    ) {}

    /** @return list<string> */
    public function handles(): array
    {
        return [
            'quality.ncr_aging',
            'quality.eightd_sla',
            'executive.risks_opportunities',
            'executive.weekly_briefing',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(string $key, User $user): array
    {
        return match ($key) {
            'quality.ncr_aging' => $this->ncrAging(),
            'quality.eightd_sla' => $this->eightdSla(),
            'executive.risks_opportunities' => $this->risksAndOpportunities($user),
            'executive.weekly_briefing' => $this->weeklyBriefing($user),
            default => [],
        };
    }

    /** @return array{total:int,segments:list<array{label:string,value:int,tone:string}>} */
    private function ncrAging(): array
    {
        $active = DB::table('non_conformance_reports')->whereIn('status', [
            NcrStatus::Open->value,
            NcrStatus::InProgress->value,
        ]);
        $now = Carbon::now();
        $buckets = [
            ['label' => '0–7 days', 'start' => $now->copy()->subDays(7), 'end' => null, 'tone' => 'success'],
            ['label' => '8–14 days', 'start' => $now->copy()->subDays(14), 'end' => $now->copy()->subDays(7), 'tone' => 'warning'],
            ['label' => '15+ days', 'start' => null, 'end' => $now->copy()->subDays(14), 'tone' => 'danger'],
        ];
        $segments = [];

        foreach ($buckets as $bucket) {
            $query = clone $active;
            if ($bucket['start']) {
                $query->where('created_at', '>=', $bucket['start']);
            }
            if ($bucket['end']) {
                $query->where('created_at', '<', $bucket['end']);
            }
            $segments[] = [
                'label' => $bucket['label'],
                'value' => $query->count(),
                'tone' => $bucket['tone'],
            ];
        }

        return ['total' => array_sum(array_column($segments, 'value')), 'segments' => $segments];
    }

    /** @return array{total:int,segments:list<array{label:string,value:int,tone:string}>} */
    private function eightdSla(): array
    {
        $active = DB::table('customer_complaints as c')
            ->leftJoin('complaint_8d_reports as r', 'r.complaint_id', '=', 'c.id')
            ->whereNotIn('c.status', [ComplaintStatus::Closed->value, ComplaintStatus::Cancelled->value]);
        $now = Carbon::now();
        $segments = [
            [
                'label' => 'Containment (D3)',
                'value' => (clone $active)->whereNotNull('c.d3_due_at')->where('c.d3_due_at', '<', $now)
                    ->whereRaw("NULLIF(TRIM(r.d3_containment), '') IS NULL")->count(),
                'tone' => 'danger',
            ],
            [
                'label' => 'Root cause (D4)',
                'value' => (clone $active)->whereNotNull('c.d4_due_at')->where('c.d4_due_at', '<', $now)
                    ->whereRaw("NULLIF(TRIM(r.d4_root_cause), '') IS NULL")->count(),
                'tone' => 'warning',
            ],
            [
                'label' => 'Finalization',
                'value' => (clone $active)->whereNotNull('c.finalize_due_at')->where('c.finalize_due_at', '<', $now)
                    ->whereNull('r.finalized_at')->count(),
                'tone' => 'danger',
            ],
        ];

        return ['total' => array_sum(array_column($segments, 'value')), 'segments' => $segments];
    }

    /** @return array{columns:list<array{key:string,label:string}>,rows:list<array<string,string|int|null>>,total_count:int} */
    private function risksAndOpportunities(User $user): array
    {
        $candidates = [];
        if ($user->hasPermission('alerts.view')) {
            $candidates[] = [
                'priority' => 'Critical',
                'signal' => DB::table('alerts')->whereNull('resolved_at')->where('is_dismissed', false)
                    ->where('severity', 'critical')->count(),
                'unit' => 'active critical alerts',
                'recommendation' => 'Review critical alerts',
                'link' => '/alerts',
            ];
        }
        if ($user->hasPermission('dashboard.chain_recovery.view')) {
            $candidates[] = [
                'priority' => 'High',
                'signal' => $this->chainRecovery->attentionCount(),
                'unit' => 'listener runs need recovery',
                'recommendation' => 'Review chain recovery runs',
                'link' => '/chains/recovery',
            ];
        }
        if ($user->hasPermission('quality.ncr.view')) {
            $ncrAging = $this->ncrAging();
            $candidates[] = [
                'priority' => 'High',
                'signal' => $ncrAging['segments'][2]['value'],
                'unit' => 'open NCRs aged 15+ days',
                'recommendation' => 'Review aged corrective actions',
                'link' => '/quality/ncrs',
            ];
        }
        if ($user->hasPermission('crm.complaints.view')) {
            $eightd = $this->eightdSla();
            $candidates[] = [
                'priority' => 'High',
                'signal' => $eightd['total'],
                'unit' => 'overdue 8D milestones',
                'recommendation' => 'Review overdue complaint 8D plans',
                'link' => '/crm/complaints',
            ];
        }
        if ($user->hasPermission('accounting.invoices.view')) {
            $arOver60 = (float) DB::table('invoices')
                ->whereIn('status', ['finalized', 'partial'])
                ->where('balance', '>', 0)
                ->where('due_date', '<', Carbon::today()->subDays(60)->toDateString())
                ->sum('balance');
            $candidates[] = [
                'priority' => 'Opportunity',
                'signal' => $arOver60,
                'unit' => 'pesos in receivables over 60 days',
                'recommendation' => 'Prioritize collection follow-up',
                'link' => '/accounting/invoices',
                'currency' => true,
            ];
        }
        if ($user->hasPermission('accounting.bills.view')) {
            $apDue = (float) DB::table('bills')
                ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])
                ->where('balance', '>', 0)
                ->whereBetween('due_date', [Carbon::today()->toDateString(), Carbon::today()->addDays(3)->toDateString()])
                ->sum('balance');
            $candidates[] = [
                'priority' => 'High',
                'signal' => $apDue,
                'unit' => 'pesos payable within 3 days',
                'recommendation' => 'Review near-term bill payments',
                'link' => '/accounting/bills',
                'currency' => true,
            ];
        }
        $rows = [];

        foreach ($candidates as $candidate) {
            if ($candidate['signal'] <= 0) {
                continue;
            }
            $signal = $candidate['currency'] ?? false
                ? '₱'.number_format((float) $candidate['signal'], 2)
                : number_format((int) $candidate['signal']).' '.$candidate['unit'];
            $rows[] = [
                'priority' => $candidate['priority'],
                'signal' => $signal,
                'recommendation' => $candidate['recommendation'],
                'link' => $candidate['link'],
            ];
        }

        return [
            'columns' => [
                ['key' => 'priority', 'label' => 'Priority'],
                ['key' => 'signal', 'label' => 'Signal'],
                ['key' => 'recommendation', 'label' => 'Next step'],
            ],
            'rows' => array_slice($rows, 0, 5),
            'total_count' => count($rows),
        ];
    }

    /** @return array{columns:list<array{key:string,label:string}>,rows:list<array<string,string|int|null>>,total_count:int} */
    private function weeklyBriefing(User $user): array
    {
        $now = Carbon::now();
        $weekStart = $now->copy()->subDays(7);
        $previousStart = $now->copy()->subDays(14);
        $summaries = [];
        if ($user->hasPermission('production.view')) {
            $production = DB::table('work_order_outputs');
            $currentUnits = (int) (clone $production)->where('recorded_at', '>=', $weekStart)->sum('good_count');
            $previousUnits = (int) (clone $production)->where('recorded_at', '>=', $previousStart)
                ->where('recorded_at', '<', $weekStart)->sum('good_count');
            $summaries[] = "Production recorded {$currentUnits} good units in the last 7 days; {$previousUnits} in the prior 7 days.";
        }
        if ($user->hasPermission('quality.ncr.view')) {
            $currentNcrs = DB::table('non_conformance_reports')->where('created_at', '>=', $weekStart)->count();
            $previousNcrs = DB::table('non_conformance_reports')->where('created_at', '>=', $previousStart)
                ->where('created_at', '<', $weekStart)->count();
            $summaries[] = "Quality logged {$currentNcrs} NCRs in the last 7 days; {$previousNcrs} in the prior 7 days.";
        }
        if ($user->hasPermission('crm.complaints.view')) {
            $currentComplaints = DB::table('customer_complaints')->where('created_at', '>=', $weekStart)->count();
            $previousComplaints = DB::table('customer_complaints')->where('created_at', '>=', $previousStart)
                ->where('created_at', '<', $weekStart)->count();
            $summaries[] = "Customer service logged {$currentComplaints} complaints in the last 7 days; {$previousComplaints} in the prior 7 days.";
        }

        return [
            'columns' => [['key' => 'summary', 'label' => 'This week']],
            'rows' => array_map(static fn (string $summary): array => ['summary' => $summary], $summaries),
            'total_count' => count($summaries),
        ];
    }
}
