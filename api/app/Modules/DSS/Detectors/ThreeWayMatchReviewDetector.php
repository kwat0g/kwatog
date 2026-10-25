<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\Accounting\Models\Bill;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;

/** D33 slice — surface persisted blocking PO/bill/GRN match snapshots for review. */
class ThreeWayMatchReviewDetector implements DetectorInterface
{
    private const CATEGORIES = [
        'qty_variance' => 'Quantity variance',
        'price_variance' => 'Price variance',
        'both' => 'Quantity and price variance',
        'grn_short' => 'Receipt quantity short',
        'duplicate_bill_line' => 'Repeated bill line',
        'unmatched_bill_line' => 'Bill line without PO match',
    ];

    public function id(): string
    {
        return 'dss.three_way_match_review';
    }

    public function domain(): string
    {
        return 'accounting';
    }

    public function ownerPermissions(): array
    {
        return ['accounting.bills.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $limit = max(1, (int) ($context['limit'] ?? 200));
        $bills = Bill::query()
            ->where('status', 'draft')
            ->whereNotNull('purchase_order_id')
            ->where('has_variances', true)
            ->where('three_way_overridden', false)
            ->where('three_way_match_snapshot->overall_status', 'blocked')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'bill_number', 'three_way_match_snapshot', 'updated_at']);

        $emissions = $bills->take($limit)->map(function (Bill $bill): DetectorEmission {
            $counts = array_fill_keys(array_keys(self::CATEGORIES), 0);
            foreach (($bill->three_way_match_snapshot['lines'] ?? []) as $line) {
                if (($line['severity'] ?? null) !== 'block') {
                    continue;
                }
                $category = $line['status'] ?? null;
                if (is_string($category) && array_key_exists($category, $counts)) {
                    $counts[$category]++;
                }
            }
            $categories = [];
            foreach (self::CATEGORIES as $key => $label) {
                if ($counts[$key] > 0) {
                    $categories[$label] = $counts[$key];
                }
            }

            return new DetectorEmission(
                type: 'three_way_match_blocked',
                severity: 'warning',
                dedupeKey: 'three-way-match:bill:'.$bill->id,
                rationale: [
                    'rule' => 'the saved PO, bill, and receipt match snapshot is blocked',
                    'bill_number' => (string) $bill->bill_number,
                    'po_number' => is_string($bill->three_way_match_snapshot['po_number'] ?? null)
                        ? $bill->three_way_match_snapshot['po_number'] : null,
                    'bill_updated_at' => $bill->updated_at?->toIso8601String(),
                    'blocking_line_categories' => $categories,
                    'evidence_scope' => 'Saved bill match state only; PO or receipt changes may not be reflected.',
                ],
                subjectType: Bill::class,
                subjectId: (int) $bill->id,
                dataSufficiency: 'partial',
                action: ['route' => '/accounting/bills/'.$bill->hash_id, 'label' => 'Review bill'],
                ownerPermissions: ['accounting.bills.view'],
                ownerRoleSlugs: ['finance_officer'],
                decisionCode: 'three_way_match_blocked',
                policyVersion: $this->version(),
                sourceReferences: [[
                    'source_kind' => 'bill',
                    'public_id' => (string) $bill->hash_id,
                    'display_reference' => (string) $bill->bill_number,
                    'destination' => '/accounting/bills/'.$bill->hash_id,
                ]],
                sourceObservedAt: $bill->updated_at,
                verificationContext: ['source_id' => (int) $bill->id],
            );
        })->all();

        return new DetectorScanResult($emissions, $bills->count(), $bills->count() <= $limit);
    }
}
