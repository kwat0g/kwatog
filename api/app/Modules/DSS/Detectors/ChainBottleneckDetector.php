<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Common\Services\ChainBottleneckService;
use App\Common\Services\SettingsService;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;

/** D26 — reuse existing cross-workflow bottleneck rules, with owner-scoped summaries. */
class ChainBottleneckDetector implements DetectorInterface
{
    public function __construct(
        private readonly ChainBottleneckService $bottlenecks,
        private readonly SettingsService $settings,
    ) {}

    public function id(): string { return 'dss.chain_bottlenecks'; }
    public function domain(): string { return 'cross_module'; }
    public function ownerPermissions(): array
    {
        return [
            'mrp.plans.view', 'inventory.issue.create', 'quality.inspections.view',
            'supply_chain.deliveries.view', 'accounting.invoices.view', 'accounting.bills.view',
            'inventory.grn.create', 'accounting.view', 'quality.ncr.view', 'payroll.periods.view',
        ];
    }
    public function version(): string { return 'v1'; }
    public function defaultParams(): array { return []; }

    public function detect(array $context = []): DetectorScanResult
    {
        $perGroupLimit = $this->settings->requiredInt('dashboard.chain_bottlenecks.result_limit', 1, 500);
        $groups = $this->bottlenecks->detectAll();
        $emissions = [];
        $rowsScanned = 0;
        $complete = true;

        foreach ($groups as $key => $rows) {
            $rowsScanned += count($rows);
            if (count($rows) >= $perGroupLimit) {
                $complete = false;
            }

            // Individual approver scope is not represented by detector permissions.
            if ($key === 'pr_pending_overdue') {
                continue;
            }

            $permission = $this->permissionFor($key);
            if ($permission === null) {
                $complete = false;
                continue;
            }
            if ($rows === []) {
                continue;
            }

            usort($rows, static fn (array $a, array $b): int => ($b['hours_stuck'] ?? 0) <=> ($a['hours_stuck'] ?? 0));
            $oldest = (int) ($rows[0]['hours_stuck'] ?? 0);
            $threshold = $this->thresholdFor($key);
            $destination = $this->routeFor($key);
            $samples = [];
            $sourceReferences = [];
            foreach (array_slice($rows, 0, 5) as $row) {
                $reference = $this->sourceReference($row, $destination);
                $sample = [
                    'document' => $row['doc_number'],
                    'status' => $row['status'],
                    'hours_stuck' => $row['hours_stuck'],
                ];
                if ($reference !== null) {
                    $sourceReferences[] = $reference;
                }
                $samples[] = $sample;
            }

            $emissions[] = new DetectorEmission(
                type: 'chain_bottleneck',
                severity: $oldest >= $threshold * 3 ? 'critical' : 'warning',
                dedupeKey: "chain:{$key}",
                rationale: [
                    'rule' => $rows[0]['label'],
                    'stuck_count' => count($rows),
                    'threshold_hours' => $threshold,
                    'oldest_hours_stuck' => $oldest,
                    'sample_documents' => $samples,
                    'source' => 'existing ChainBottleneckService rule',
                ],
                confidence: 1.0,
                action: ['route' => $destination, 'label' => 'Open workflow queue →'],
                ownerPermissions: [$permission],
                ownerRoleSlugs: [(string) ($rows[0]['audience'] ?? '')],
                sourceReferences: $sourceReferences,
            );
        }

        return new DetectorScanResult($emissions, $rowsScanned, $complete);
    }

    private function permissionFor(string $key): ?string
    {
        return match ($key) {
            'so_at_mrp_planned' => 'mrp.plans.view',
            'wo_confirmed_unstarted' => 'inventory.issue.create',
            'inspection_outgoing_pending', 'grn_without_incoming_qc', 'return_without_inspection' => 'quality.inspections.view',
            'delivery_scheduled_overdue' => 'supply_chain.deliveries.view',
            'invoice_draft_overdue', 'delivery_confirmed_without_invoice' => 'accounting.invoices.view',
            'bill_unpaid_overdue', 'grn_accepted_without_bill', 'bill_three_way_manual_review' => 'accounting.bills.view',
            'production_output_without_receipt' => 'inventory.grn.create',
            'inventory_movement_without_gl' => 'accounting.view',
            'complaint_without_ncr' => 'quality.ncr.view',
            'payroll_gl_without_journal' => 'payroll.periods.view',
            default => null,
        };
    }

    private function thresholdFor(string $key): int
    {
        $policy = $this->settings->get('dashboard.chain_bottlenecks', []);

        return max(1, (int) (is_array($policy) ? ($policy[$key]['hours'] ?? 1) : 1));
    }

    private function routeFor(string $key): string
    {
        return match ($key) {
            'so_at_mrp_planned' => '/crm/sales-orders',
            'wo_confirmed_unstarted', 'production_output_without_receipt' => '/inventory',
            'inspection_outgoing_pending', 'grn_without_incoming_qc', 'return_without_inspection' => '/quality/inspections',
            'delivery_scheduled_overdue' => '/supply-chain/deliveries',
            'delivery_confirmed_without_invoice' => '/accounting/invoices',
            'invoice_draft_overdue' => '/accounting/invoices',
            'bill_unpaid_overdue', 'grn_accepted_without_bill', 'bill_three_way_manual_review' => '/accounting/bills',
            'inventory_movement_without_gl' => '/accounting/journal-entries',
            'payroll_gl_without_journal' => '/payroll/periods',
            'complaint_without_ncr' => '/quality/ncrs',
            default => '/dashboard',
        };
    }

    /** @return array{source_kind: string, public_id: string, display_reference: string, destination: string}|null */
    private function sourceReference(array $row, string $destination): ?array
    {
        if (! in_array($row['entity_type'] ?? null, [
            'sales_order', 'work_order', 'inspection', 'delivery', 'invoice', 'purchase_request',
            'bill', 'grn', 'stock_movement', 'return_request', 'customer_complaint', 'payroll_period',
        ], true)) {
            return null;
        }

        $id = $row['entity_id'] ?? null;
        $document = $row['doc_number'] ?? null;
        if (! is_string($id) || $id === '' || ctype_digit($id) || ! is_string($document) || $document === '') {
            return null;
        }

        return [
            'source_kind' => $row['entity_type'],
            'public_id' => $id,
            'display_reference' => $document,
            'destination' => $destination,
        ];
    }
}
