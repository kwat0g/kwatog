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
        'partial_received'  => 'partial_received',
        'received'          => 'received',
        'fully_received'    => 'received',
        'closed'            => 'closed',
        'cancelled'         => 'closed',
    ];

    /** @var array<int,string> Delivery (Chain 1, late stages). */
    private const STEPS_DELIVERY = [
        'scheduled',
        'in_transit',
        'delivered',
        'confirmed',
    ];

    private const STATUS_MAP_DELIVERY = [
        'scheduled'  => 'scheduled',
        'in_transit' => 'in_transit',
        'delivered'  => 'delivered',
        'confirmed'  => 'confirmed',
        'cancelled'  => 'confirmed',
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
        'rejected'   => 'closed',
        'closed'     => 'closed',
    ];

    /**
     * @return array{0: string, 1: array<int,string>} [activeStep, completedSteps]
     */
    public static function resolve(string $entityType, string $status): array
    {
        [$steps, $statusMap] = match ($entityType) {
            'sales_order'    => [self::STEPS_SALES_ORDER, self::STATUS_MAP_SALES_ORDER],
            'work_order'     => [self::STEPS_WORK_ORDER,  self::STATUS_MAP_WORK_ORDER],
            'purchase_order' => [self::STEPS_PURCHASE_ORDER, self::STATUS_MAP_PURCHASE_ORDER],
            'delivery'       => [self::STEPS_DELIVERY,    self::STATUS_MAP_DELIVERY],
            'grn'            => [self::STEPS_GRN,         self::STATUS_MAP_GRN],
            default          => [[], []],
        };

        $active = $statusMap[$status] ?? ($steps[0] ?? 'unknown');
        $idx    = array_search($active, $steps, true);
        $completed = $idx === false ? [] : array_slice($steps, 0, (int) $idx);

        return [$active, $completed];
    }

    /** @return array<int,string> */
    public static function allowedTypes(): array
    {
        return ['sales_order', 'work_order', 'purchase_order', 'delivery', 'grn'];
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
            default          => 'dashboard.view_bottlenecks', // fallback (unused; defensive)
        };
    }
}
