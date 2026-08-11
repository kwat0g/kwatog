<?php

declare(strict_types=1);

namespace App\Common\Support;

/**
 * Series C — Single source of truth for chain step ordering per entity type.
 *
 * Each chain is an ordered array of step keys. The active step is the FIRST
 * entry in the entity's status-to-step map; completed steps are the prefix
 * of the chain up to (but excluding) the active step.
 *
 * Mirrored on the SPA in `spa/src/lib/chains/*.ts`. Keep the two in sync.
 *
 * Adding a chain:
 *   1. Add a const ALL_STEPS_<TYPE> array
 *   2. Add a STATUS_MAP_<TYPE> mapping status string → step key
 *   3. Add the type slug to allowedTypes()
 *   4. Mirror the steps + labels on the SPA
 */
final class ChainDefinitions
{
    /** @var array<int,string> Sales Order (Chain 1) — order_to_cash. */
    private const STEPS_SALES_ORDER = [
        'draft',
        'confirmed',
        'in_production',
        'qc_outgoing',
        'ready_for_delivery',
        'delivered',
        'invoiced',
        'paid',
        'closed',
    ];

    /** @var array<string,string> SO status → active step key. */
    private const STATUS_MAP_SALES_ORDER = [
        'draft'              => 'draft',
        'confirmed'          => 'confirmed',
        'in_production'      => 'in_production',
        'partial_production' => 'in_production',
        'ready_for_delivery' => 'ready_for_delivery',
        'partially_delivered' => 'delivered',
        'partial_delivered'  => 'delivered',
        'delivered'          => 'delivered',
        'invoiced'           => 'invoiced',
        'paid'               => 'paid',
        'closed'             => 'closed',
        'cancelled'          => 'closed',
    ];

    /** @var array<int,string> Work Order. */
    private const STEPS_WORK_ORDER = [
        'draft',
        'confirmed',
        'in_progress',
        'paused',
        'completed',
        'qc_outgoing',
        'closed',
    ];

    private const STATUS_MAP_WORK_ORDER = [
        'draft'       => 'draft',
        'planned'     => 'draft',
        'confirmed'   => 'confirmed',
        'in_progress' => 'in_progress',
        'paused'      => 'paused',
        'completed'   => 'completed',
        'closed'      => 'closed',
        'cancelled'   => 'closed',
    ];

    /** @var array<int,string> Purchase Order (Chain 2). */
    private const STEPS_PURCHASE_ORDER = [
        'draft',
        'pending_approval',
        'approved',
        'sent',
        'partial_received',
        'received',
        'closed',
    ];

    private const STATUS_MAP_PURCHASE_ORDER = [
        'draft'             => 'draft',
        'pending_approval'  => 'pending_approval',
        'approved'          => 'approved',
        'sent'              => 'sent',
        'partially_received' => 'partial_received',
        'partial_received'  => 'partial_received',
        'received'          => 'received',
        'fully_received'    => 'received',
        'closed'            => 'closed',
        'cancelled'         => 'closed',
    ];

    /** @var array<int,string> Delivery (Chain 1, late stages). */
    private const STEPS_DELIVERY = [
        'scheduled',
        'loading',
        'in_transit',
        'delivered',
        'confirmed',
    ];

    private const STATUS_MAP_DELIVERY = [
        'scheduled'  => 'scheduled',
        'loading'    => 'loading',
        'in_transit' => 'in_transit',
        'delivered'  => 'delivered',
        'confirmed'  => 'confirmed',
        'cancelled'  => 'confirmed',
    ];

    /** @var array<int,string> Supplier Bill (Chain 2 finale). */
    private const STEPS_BILL = [
        'draft',
        'posted',
        'partial',
        'paid',
        'closed',
    ];

    private const STATUS_MAP_BILL = [
        'draft'     => 'draft',
        'unpaid'    => 'posted',
        'partial'   => 'partial',
        'paid'      => 'paid',
        'cancelled' => 'closed',
        'closed'    => 'closed',
    ];

    /** @var array<int,string> Customer Invoice (Chain 1 finale). */
    private const STEPS_INVOICE = [
        'draft',
        'finalized',
        'partial',
        'paid',
        'closed',
    ];

    private const STATUS_MAP_INVOICE = [
        'draft'     => 'draft',
        'finalized' => 'finalized',
        'partial'   => 'partial',
        'paid'      => 'paid',
        'cancelled' => 'closed',
        'closed'    => 'closed',
    ];

    /** @var array<int,string> Goods Receipt Note (Chain 2). */
    private const STEPS_GRN = [
        'draft',
        'received',
        'qc_incoming',
        'accepted',
        'closed',
    ];

    private const STATUS_MAP_GRN = [
        'draft'      => 'draft',
        'received'   => 'received',
        'inspecting' => 'qc_incoming',
        'pending_qc' => 'qc_incoming',
        'accepted'   => 'accepted',
        'partial_accepted' => 'accepted',
        'rejected'   => 'closed',
        'closed'     => 'closed',
    ];

    /** @return array<string,array{steps: list<string>, status_map: array<string,string>}> */
    public static function defaults(): array
    {
        return [
            'sales_order' => ['steps' => self::STEPS_SALES_ORDER, 'status_map' => self::STATUS_MAP_SALES_ORDER],
            'work_order' => ['steps' => self::STEPS_WORK_ORDER, 'status_map' => self::STATUS_MAP_WORK_ORDER],
            'purchase_order' => ['steps' => self::STEPS_PURCHASE_ORDER, 'status_map' => self::STATUS_MAP_PURCHASE_ORDER],
            'delivery' => ['steps' => self::STEPS_DELIVERY, 'status_map' => self::STATUS_MAP_DELIVERY],
            'grn' => ['steps' => self::STEPS_GRN, 'status_map' => self::STATUS_MAP_GRN],
            'bill' => ['steps' => self::STEPS_BILL, 'status_map' => self::STATUS_MAP_BILL],
            'invoice' => ['steps' => self::STEPS_INVOICE, 'status_map' => self::STATUS_MAP_INVOICE],
        ];
    }

    /** @return array<string,array{steps: list<string>, status_map: array<string,string>}> */
    private static function configured(): array
    {
        $value = app(\App\Common\Services\SettingsService::class)->get('workflow.chain_definitions');
        if (! is_array($value) || $value === []) return self::defaults();

        $valid = [];
        foreach ($value as $type => $definition) {
            if (! is_string($type) || ! is_array($definition)) continue;
            $steps = array_values(array_filter((array) ($definition['steps'] ?? []), 'is_string'));
            $statusMap = array_filter((array) ($definition['status_map'] ?? []), 'is_string');
            if ($steps !== [] && $statusMap !== []) {
                $valid[$type] = ['steps' => $steps, 'status_map' => $statusMap];
            }
        }

        // Code is the source of truth for chain definitions: any chain or
        // status added in defaults() must resolve even when a pre-existing
        // settings row (seeded before that status existed) is stale. Keep
        // explicit admin mappings authoritative, while backfilling missing
        // mappings from the current code contract.
        $defaults = self::defaults();
        foreach ($valid as $type => &$definition) {
            if (isset($defaults[$type])) {
                // A settings row can predate a newly added lifecycle step.
                // Preserve any administrator-defined ordering and custom
                // steps, but insert code-defined steps at their canonical
                // position so an old cached/configured definition cannot
                // collapse a real status back onto an earlier step.
                $configuredSteps = array_values($definition['steps']);
                foreach ($defaults[$type]['steps'] as $defaultIndex => $defaultStep) {
                    if (in_array($defaultStep, $configuredSteps, true)) {
                        continue;
                    }

                    $insertAt = count($configuredSteps);
                    foreach ($configuredSteps as $configuredIndex => $configuredStep) {
                        $configuredDefaultIndex = array_search(
                            $configuredStep,
                            $defaults[$type]['steps'],
                            true,
                        );
                        if ($configuredDefaultIndex !== false && $configuredDefaultIndex > $defaultIndex) {
                            $insertAt = $configuredIndex;
                            break;
                        }
                    }

                    array_splice($configuredSteps, $insertAt, 0, [$defaultStep]);
                }
                $definition['steps'] = $configuredSteps;
                $definition['status_map'] = array_merge(
                    $defaults[$type]['status_map'],
                    $definition['status_map'],
                );
            }
        }
        unset($definition);

        return array_merge($defaults, $valid);
    }

    /**
     * @return array{0: string, 1: array<int,string>} [activeStep, completedSteps]
     */
    public static function resolve(string $entityType, string $status): array
    {
        $definition = self::configured()[$entityType] ?? null;
        $steps = $definition['steps'] ?? [];
        $statusMap = $definition['status_map'] ?? [];

        $active = $statusMap[$status] ?? ($steps[0] ?? 'unknown');
        $idx    = array_search($active, $steps, true);
        $completed = $idx === false ? [] : array_slice($steps, 0, (int) $idx);

        return [$active, $completed];
    }

    /**
     * Resolve a status for a durable write-side chain event.
     *
     * The read-side resolver intentionally falls back to the first step so a
     * legacy record can still render. A write must fail closed instead: an
     * unmapped status is a contract drift that would otherwise publish false
     * chain evidence.
     *
     * @return array{0: string, 1: array<int,string>}
     */
    public static function resolveStrict(string $entityType, string $status): array
    {
        $definition = self::configured()[$entityType] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown chain entity type [{$entityType}].");
        }

        $statusMap = $definition['status_map'];
        if (! array_key_exists($status, $statusMap)) {
            throw new \InvalidArgumentException(
                "Status [{$status}] is not mapped for chain entity type [{$entityType}].",
            );
        }

        $active = $statusMap[$status];
        $idx = array_search($active, $definition['steps'], true);
        if ($idx === false) {
            throw new \InvalidArgumentException(
                "Chain step [{$active}] for [{$entityType}] is not present in its ordered definition.",
            );
        }

        return [$active, array_slice($definition['steps'], 0, (int) $idx)];
    }

    /** @return array<int,string> */
    public static function allowedTypes(): array
    {
        return array_keys(self::configured());
    }

    /** Required permission to listen to a given chain channel. */
    public static function viewPermission(string $entityType): string
    {
        return match ($entityType) {
            'sales_order'    => 'crm.sales_orders.view',
            'work_order'     => 'production.work_orders.view',
            'purchase_order' => 'purchasing.view',
            'delivery'       => 'supply_chain.view',
            'grn'            => 'inventory.view',
            'bill'           => 'accounting.bills.view',
            'invoice'        => 'accounting.invoices.view',
            'stock_movement' => 'accounting.journal.view',
            'return_request' => 'return_management.view',
            'customer_complaint' => 'crm.complaints.manage',
            default          => 'dashboard.view_bottlenecks', // fallback (unused; defensive)
        };
    }
}
