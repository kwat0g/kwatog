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

    public function test_bill_unpaid_maps_to_posted_step(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('bill', 'unpaid');
        $this->assertSame('posted', $active);
        $this->assertSame(['draft'], $completed);
    }

    public function test_bill_partial_and_paid_advance_the_chain(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('bill', 'partial');
        $this->assertSame('partial', $active);
        $this->assertContains('posted', $completed);

        [$paidActive, $paidCompleted] = ChainDefinitions::resolve('bill', 'paid');
        $this->assertSame('paid', $paidActive);
        $this->assertSame(['draft', 'posted', 'partial'], $paidCompleted);
    }

    public function test_bill_cancelled_collapses_to_closed(): void
    {
        [$active, $completed] = ChainDefinitions::resolve('bill', 'cancelled');
        $this->assertSame('closed', $active);
    }

    public function test_invoice_chain_resolves_statuses(): void
    {
        [$draft] = ChainDefinitions::resolve('invoice', 'draft');
        $this->assertSame('draft', $draft);

        [$finalized] = ChainDefinitions::resolve('invoice', 'finalized');
        $this->assertSame('finalized', $finalized);

        [$paid, $completed] = ChainDefinitions::resolve('invoice', 'paid');
        $this->assertSame('paid', $paid);
        $this->assertSame(['draft', 'finalized', 'partial'], $completed);

        [$cancelled] = ChainDefinitions::resolve('invoice', 'cancelled');
        $this->assertSame('closed', $cancelled);
    }

    public function test_allowed_types_includes_all_chains(): void
    {
        $types = ChainDefinitions::allowedTypes();
        foreach (['sales_order', 'work_order', 'purchase_order', 'delivery', 'grn', 'bill', 'invoice'] as $t) {
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
        $this->assertSame('accounting.bills.view',         ChainDefinitions::viewPermission('bill'));
        $this->assertSame('accounting.invoices.view',      ChainDefinitions::viewPermission('invoice'));
    }
}
