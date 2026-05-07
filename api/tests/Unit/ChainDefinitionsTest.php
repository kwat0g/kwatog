<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Support\ChainDefinitions;
use Tests\TestCase;

/**
 * Series C — Task C4. Unit tests for the chain step resolver.
 *
 * Pure logic, no DB, no Reverb — runs in milliseconds and pins the
 * status-to-step mapping the SPA relies on.
 */
class ChainDefinitionsTest extends TestCase
{
    public function test_sales_order_confirmed_resolves_to_confirmed_step(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('sales_order', 'confirmed');
        $this->assertSame('confirmed', $active);
        $this->assertSame(['draft'], $completed);
    }

    public function test_sales_order_in_production_completes_first_three_steps(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('sales_order', 'in_production');
        $this->assertSame('in_production', $active);
        $this->assertSame(['draft', 'confirmed'], $completed);
    }

    public function test_partial_production_collapses_to_in_production(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('sales_order', 'partial_production');
        $this->assertSame('in_production', $active);
        $this->assertSame(['draft', 'confirmed'], $completed);
    }

    public function test_unknown_status_falls_back_to_first_step(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('sales_order', 'wat');
        $this->assertSame('draft', $active);
        $this->assertSame([], $completed);
    }

    public function test_work_order_completed_status(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('work_order', 'completed');
        $this->assertSame('completed', $active);
        $this->assertContains('confirmed', $completed);
        $this->assertContains('in_progress', $completed);
    }

    public function test_grn_pending_qc_maps_to_qc_incoming(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('grn', 'pending_qc');
        $this->assertSame('qc_incoming', $active);
        $this->assertSame(['draft', 'received'], $completed);
    }

    public function test_purchase_order_fully_received_collapses_to_received(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('purchase_order', 'fully_received');
        $this->assertSame('received', $active);
        $this->assertContains('approved', $completed);
    }

    public function test_unknown_entity_type_returns_unknown(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('does_not_exist', 'any');
        $this->assertSame('unknown', $active);
        $this->assertSame([], $completed);
    }

    public function test_allowed_types_includes_all_five_chains(): void
    {
        $types = ChainDefinitions::allowedTypes();
        foreach (['sales_order', 'work_order', 'purchase_order', 'delivery', 'grn'] as $t) {
            $this->assertContains($t, $types);
        }
    }

    public function test_view_permission_per_entity_type(): void
    {
        $this->assertSame('crm.sales_orders.view',         ChainDefinitions::viewPermission('sales_order'));
        $this->assertSame('production.work_orders.view',   ChainDefinitions::viewPermission('work_order'));
        $this->assertSame('purchasing.view',               ChainDefinitions::viewPermission('purchase_order'));
        $this->assertSame('supply_chain.view',             ChainDefinitions::viewPermission('delivery'));
        $this->assertSame('inventory.view',                ChainDefinitions::viewPermission('grn'));
    }
}
