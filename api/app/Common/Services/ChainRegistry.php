<?php

declare(strict_types=1);

namespace App\Common\Services;

use InvalidArgumentException;

/**
 * WS-D.1 — Single source of truth for chain step definitions.
 *
 * Today the same chain (Order-to-Cash, Procure-to-Pay, Hire-to-Retire,
 * Leave approval, NCR) is described in:
 *
 *   - Per-controller endpoints (sales-orders/{id}/chain, work-orders/{id}/chain)
 *   - Per-page TS builders (spa/src/lib/chains/*.ts)
 *   - The Plant-Manager dashboard's stage-breakdown panel
 *
 * Drift between these copies has bitten us before. The registry exists so
 * the canonical step list lives in **one** place and the SPA can fetch it
 * from `GET /api/v1/chains/{key}/definition`.
 *
 * State resolution (which step is `active` / `done` / `pending` for a
 * specific entity) intentionally stays in the existing per-domain
 * services/builders — this slice only centralizes definitions to stop
 * the labels drifting.
 */
class ChainRegistry
{
    /**
     * @return array<string, array{key:string, label:string, steps:array<int, array{key:string, label:string}>}>
     */
    private function catalog(): array
    {
        return [
            'sales_order' => [
                'key'   => 'sales_order',
                'label' => 'Order to Cash',
                'steps' => [
                    ['key' => 'draft',          'label' => 'Draft'],
                    ['key' => 'confirmed',      'label' => 'Confirmed'],
                    ['key' => 'in_production',  'label' => 'In Production'],
                    ['key' => 'delivered',      'label' => 'Delivered'],
                    ['key' => 'invoiced',       'label' => 'Invoiced'],
                    ['key' => 'collected',      'label' => 'Collected'],
                ],
            ],
            'purchase_order' => [
                'key'   => 'purchase_order',
                'label' => 'Procure to Pay',
                'steps' => [
                    ['key' => 'draft',     'label' => 'Draft'],
                    ['key' => 'pending',   'label' => 'Pending Approval'],
                    ['key' => 'approved',  'label' => 'Approved'],
                    ['key' => 'sent',      'label' => 'Sent to Vendor'],
                    ['key' => 'received',  'label' => 'Goods Received'],
                    ['key' => 'billed',    'label' => 'Vendor Billed'],
                    ['key' => 'paid',      'label' => 'Paid'],
                ],
            ],
            'work_order' => [
                'key'   => 'work_order',
                'label' => 'Production',
                'steps' => [
                    ['key' => 'planned',     'label' => 'Planned'],
                    ['key' => 'released',    'label' => 'Released'],
                    ['key' => 'in_progress', 'label' => 'In Progress'],
                    ['key' => 'inspected',   'label' => 'QC Inspected'],
                    ['key' => 'completed',   'label' => 'Completed'],
                ],
            ],
            'leave_request' => [
                'key'   => 'leave_request',
                'label' => 'Leave Approval',
                'steps' => [
                    ['key' => 'submitted',     'label' => 'Submitted'],
                    ['key' => 'pending_dept',  'label' => 'Department Head Review'],
                    ['key' => 'pending_hr',    'label' => 'HR Review'],
                    ['key' => 'approved',      'label' => 'Approved'],
                ],
            ],
            'ncr' => [
                'key'   => 'ncr',
                'label' => 'Non-Conformance Resolution',
                'steps' => [
                    ['key' => 'open',         'label' => 'Open'],
                    ['key' => 'containment',  'label' => 'Containment'],
                    ['key' => 'root_cause',   'label' => 'Root Cause'],
                    ['key' => 'corrective',   'label' => 'Corrective Action'],
                    ['key' => 'verified',     'label' => 'Verified'],
                    ['key' => 'closed',       'label' => 'Closed'],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->catalog());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->catalog());
    }

    /**
     * @return array{key:string, label:string, steps:array<int, array{key:string, label:string}>}
     */
    public function definition(string $key): array
    {
        $catalog = $this->catalog();
        if (! array_key_exists($key, $catalog)) {
            throw new InvalidArgumentException("Unknown chain key [{$key}].");
        }
        return $catalog[$key];
    }

    /**
     * @return array<int, array{key:string, label:string, steps:array<int, array{key:string, label:string}>}>
     */
    public function all(): array
    {
        return array_values($this->catalog());
    }
}
