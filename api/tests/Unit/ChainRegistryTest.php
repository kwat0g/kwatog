<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\ChainRegistry;
use Tests\TestCase;

/**
 * WS-D.1 — Chain registry: single source of truth for chain step definitions.
 *
 * Today, chain step lists are hard-coded into per-page components and
 * per-controller endpoints (sales-orders/{id}/chain, work-orders/{id}/chain,
 * lib/chains/delivery.ts, etc). Drift between server and SPA is a real risk
 * — the registry exists so server, SPA, and tests share one source of truth.
 *
 * This test locks the contract: every supported chain key returns an
 * ordered, non-empty list of steps, each with a stable key and a human
 * label. Anything looser would let drift back in.
 */
class ChainRegistryTest extends TestCase
{
    private function registry(): ChainRegistry
    {
        return new ChainRegistry();
    }

    public function test_registry_lists_every_supported_chain_key(): void
    {
        $keys = $this->registry()->keys();

        // The five chains that have production-grade implementations today.
        // Adding a chain to this list intentionally requires updating the
        // registry to keep server and SPA in sync.
        $this->assertContains('sales_order',     $keys);
        $this->assertContains('purchase_order',  $keys);
        $this->assertContains('work_order',      $keys);
        $this->assertContains('leave_request',   $keys);
        $this->assertContains('ncr',             $keys);
    }

    public function test_each_chain_returns_an_ordered_non_empty_step_list(): void
    {
        foreach ($this->registry()->keys() as $key) {
            $def = $this->registry()->definition($key);

            $this->assertSame($key, $def['key']);
            $this->assertNotEmpty($def['steps'], "chain {$key} has no steps");

            foreach ($def['steps'] as $step) {
                $this->assertArrayHasKey('key',   $step);
                $this->assertArrayHasKey('label', $step);
                $this->assertNotEmpty($step['key']);
                $this->assertNotEmpty($step['label']);
            }
        }
    }

    public function test_unknown_chain_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry()->definition('not_a_real_chain');
    }

    public function test_sales_order_chain_has_the_o2c_steps_in_canonical_order(): void
    {
        $steps = collect($this->registry()->definition('sales_order')['steps'])
            ->pluck('key')
            ->all();

        $this->assertSame(
            ['draft', 'confirmed', 'in_production', 'delivered', 'invoiced', 'collected'],
            $steps,
        );
    }
}
