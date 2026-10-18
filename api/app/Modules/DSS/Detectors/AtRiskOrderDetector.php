<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\MrpPlan;

/**
 * D03 — at-risk customer orders. Reuses the MRP plan diagnostics the PPC
 * team already reviews: the active plan per sales order records
 * `shortages_found` and line-level `diagnostics` on every (re)run. Adds what
 * the MRP screens lack: a prioritized, aggregated risk view per bucket.
 *
 * One aggregated emission per risk bucket (never one row per order):
 * material shortage → critical (demand cannot flow without supply),
 * planning blockers → warning (demand explosion/executability degraded).
 * Superseded/cancelled plans are not the live review surface, and terminal
 * orders are out of the order book. Rows labeled audience
 * ppc/sales/VP; per-row enforcement lives in RecommendationService.
 * Deterministic counts → confidence 1.0. Read-only.
 */
class AtRiskOrderDetector implements DetectorInterface
{
    public const ID = 'dss.at_risk_orders';

    public function __construct() {}

    public function id(): string
    {
        return self::ID;
    }

    public function domain(): string
    {
        return 'crm';
    }

    public function ownerPermissions(): array
    {
        // Reachability only. Row-level access is enforced per audience_roles
        // in RecommendationService.
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return ['max_evidence_per_bucket' => 5];
    }

    /** @return list<DetectorEmission> */
    public function detect(array $context = []): array
    {
        $maxEvidence = max(1, (int) ($context['max_evidence'] ?? $this->defaultParams()['max_evidence_per_bucket']));

        $shortages = [];
        $reviews = [];
        $shortageTotal = 0;

        /** @var MrpPlan $plan */
        foreach (MrpPlan::query()
            ->where('status', MrpPlanStatus::Active->value)
            ->with('salesOrder:id,so_number,status,customer_id', 'salesOrder.customer:id,name')
            ->orderBy('sales_order_id')
            ->get() as $plan) {
            $so = $plan->salesOrder;
            if ($so === null || in_array($so->status->value, SalesOrderStatus::terminalForPipeline(), true)) {
                continue;
            }

            if ($plan->shortages_found > 0) {
                $shortages[] = $plan;
                $shortageTotal += $plan->shortages_found;
            }

            if (is_array($plan->diagnostics) && $plan->diagnostics !== []) {
                $reviews[] = $plan;
            }
        }

        $emissions = [];

        if ($shortages !== []) {
            $emissions[] = new DetectorEmission(
                type: 'at_risk_order',
                severity: 'critical',
                dedupeKey: 'at-risk:mrp_shortage',
                rationale: [
                    'rule' => 'at-risk order scan (MRP plan diagnostics)',
                    'bucket' => 'mrp_shortage',
                    'affected_order_count' => count($shortages),
                    'shortage_line_total' => $shortageTotal,
                    'capped_at_max_evidence' => count($shortages) > $maxEvidence,
                    'audience_roles' => ['ppc_head', 'sales_officer', 'vice_president'],
                    'evidence' => $this->evidenceShortages($shortages, $maxEvidence),
                    'why_it_matters' => 'Orders with a shortage in the latest MRP plan cannot flow to production or delivery until the supply gap closes.',
                ],
                confidence: 1.0,
                action: ['route' => '/crm/sales-orders', 'label' => 'Open order book →'],
            );
        }

        if ($reviews !== []) {
            $emissions[] = new DetectorEmission(
                type: 'at_risk_order',
                severity: 'warning',
                dedupeKey: 'at-risk:mrp_supply_review',
                rationale: [
                    'rule' => 'at-risk order scan (MRP plan diagnostics)',
                    'bucket' => 'mrp_supply_review',
                    'affected_order_count' => count($reviews),
                    'shortage_line_total' => 0,
                    'capped_at_max_evidence' => count($reviews) > $maxEvidence,
                    'audience_roles' => ['ppc_head', 'sales_officer', 'vice_president'],
                    'evidence' => $this->evidenceReviews($reviews, $maxEvidence),
                    'why_it_matters' => 'Planning blockers (supply review, missing BOM) on the active plan degrade what MRP can commit for these orders.',
                ],
                confidence: 1.0,
                action: ['route' => '/crm/sales-orders', 'label' => 'Open order book →'],
            );
        }

        return $emissions;
    }

    /** @param list<MrpPlan> $plans */
    private function evidenceShortages(array $plans, int $maxEvidence): array
    {
        return array_map(
            static fn (MrpPlan $plan): string => sprintf(
                '%s (%s) — %d shortage line(s)',
                $plan->salesOrder?->so_number ?? '?',
                $plan->salesOrder?->customer?->name ?? '?',
                $plan->shortages_found,
            ),
            array_slice($plans, 0, $maxEvidence),
        );
    }

    /** @param list<MrpPlan> $plans */
    private function evidenceReviews(array $plans, int $maxEvidence): array
    {
        return array_map(
            static function (MrpPlan $plan): string {
                $types = collect($plan->diagnostics ?? [])
                    ->pluck('type')
                    ->filter()
                    ->unique()
                    ->take(3)
                    ->implode(', ');

                return sprintf('%s — %s', $plan->salesOrder?->so_number ?? '?', $types !== '' ? $types : 'planning blocker');
            },
            array_slice($plans, 0, $maxEvidence),
        );
    }
}
