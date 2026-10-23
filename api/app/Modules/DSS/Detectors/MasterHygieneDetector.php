<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * D27 — master-data hygiene score. Bad master data silently breaks every
 * downstream DSS (replenishment skips items without reorder points, the
 * supplier selector skips items without qualified suppliers), so the gaps get
 * their own detector with fix links instead of failing quietly elsewhere.
 *
 * One aggregated emission per gap type (never one row per item — thousands of
 * rows would spam the Decision Center): count + sample evidence + fix route.
 * Deterministic counts → confidence 1.0. Read-only; chunked sample queries.
 */
class MasterHygieneDetector implements DetectorInterface
{
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
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return ['max_evidence_per_gap' => 10, 'critical_count' => 50];
    }

    /** @return list<DetectorEmission> */
    public function detect(array $context = []): array
    {
        $maxEvidence = max(1, (int) ($context['max_evidence'] ?? $this->defaultParams()['max_evidence_per_gap']));
        $criticalAt = max(1, (int) ($context['critical_count'] ?? $this->defaultParams()['critical_count']));

        $emissions = [];

        $gaps = [
            'items_missing_reorder_point' => $this->itemGap(
                fn ($query) => $query->where(function ($query): void {
                    $query->whereNull('reorder_point')->orWhere('reorder_point', '<=', 0);
                }),
                'code', '/inventory/items', 'Items without a usable reorder point are invisible to auto-replenishment.'
            ),
            'items_missing_safety_stock' => $this->itemGap(
                fn ($query) => $query->whereNull('safety_stock'),
                'code', '/inventory/items', 'Items without safety stock lose their MRP buffer.'
            ),
            'items_missing_lead_time' => $this->itemGap(
                fn ($query) => $query->where(function ($query): void {
                    $query->whereNull('lead_time_days')->orWhere('lead_time_days', '<=', 0);
                }),
                'code', '/inventory/items', 'Items without lead time fall back to the global default in planning.'
            ),
            'items_missing_standard_cost' => $this->itemGap(
                fn ($query) => $query->where(function ($query): void {
                    $query->whereNull('standard_cost')->orWhere('standard_cost', '<=', 0);
                }),
                'code', '/inventory/items', 'Items without standard cost block auto-PR creation and distort margins.'
            ),
            'items_without_approved_supplier' => $this->itemGap(
                fn ($query) => $query->whereNotExists(function ($exists): void {
                    $exists->select(DB::raw(1))->from('approved_suppliers')
                        ->whereColumn('approved_suppliers.item_id', 'items.id');
                }),
                'code', '/purchasing/suppliers', 'Items with no approved supplier cannot be auto-sourced.'
            ),
            'customers_missing_credit_limit' => $this->customerGap(),
            'products_without_active_bom' => $this->productGap(),
        ];

        foreach ($gaps as $key => $gap) {
            if ($gap['count'] === 0) {
                continue;
            }

            $emissions[] = new DetectorEmission(
                type: 'master_hygiene',
                severity: $gap['count'] >= $criticalAt ? 'critical' : 'warning',
                dedupeKey: "hygiene:{$key}",
                rationale: [
                    'rule' => 'master-data completeness scan',
                    'gap' => $key,
                    'affected_count' => $gap['count'],
                    'evidence' => array_slice($gap['evidence'], 0, $maxEvidence),
                    'why_it_matters' => $gap['why'],
                ],
                confidence: 1.0,
                action: ['route' => $gap['fix_route'], 'label' => 'Open master data →'],
            );
        }

        return $emissions;
    }

    /** @return array{count: int, evidence: list<string>, why: string, fix_route: string} */
    private function itemGap(callable $scope, string $column, string $fixRoute, string $why): array
    {
        $base = Item::query()->where('is_active', true);
        $scope($base);

        return [
            'count' => (clone $base)->count(),
            'evidence' => (clone $base)->orderBy('id')->limit(50)->pluck($column)->all(),
            'why' => $why,
            'fix_route' => $fixRoute,
        ];
    }

    /** @return array{count: int, evidence: list<string>, why: string, fix_route: string} */
    private function customerGap(): array
    {
        $base = Customer::query()->whereNull('credit_limit');

        return [
            'count' => (clone $base)->count(),
            'evidence' => (clone $base)->orderBy('id')->limit(50)->pluck('name')->all(),
            'why' => 'Customers without credit limits cannot be checked at order acceptance (D12).',
            'fix_route' => '/crm/customers',
        ];
    }

    /** @return array{count: int, evidence: list<string>, why: string, fix_route: string} */
    private function productGap(): array
    {
        $base = Product::query()->where('is_active', true)
            ->whereNotExists(function ($exists): void {
                $exists->select(DB::raw(1))->from('bill_of_materials')
                    ->whereColumn('bill_of_materials.product_id', 'products.id')
                    ->where('bill_of_materials.is_active', true);
            });

        return [
            'count' => (clone $base)->count(),
            'evidence' => (clone $base)->orderBy('id')->limit(50)->pluck('part_number')->all(),
            'why' => 'Products without an active BOM cannot be MRP-planned.',
            'fix_route' => '/mrp/boms',
        ];
    }
}
