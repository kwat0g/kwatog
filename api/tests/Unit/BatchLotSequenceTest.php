<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\DocumentSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADV3 — IATF 16949 batch + lot sequence generation.
 */
class BatchLotSequenceTest extends TestCase
{
    use RefreshDatabase;
    private function svc(): DocumentSequenceService
    {
        return app(DocumentSequenceService::class);
    }

    public function test_production_batch_format(): void
    {
        $svc = $this->svc();
        $first = $svc->generate('production_batch');
        $second = $svc->generate('production_batch');

        $now = now();
        $expectedPrefix = sprintf('BATCH-%04d%02d-', $now->year, $now->month);

        $this->assertStringStartsWith($expectedPrefix, $first);
        $this->assertStringStartsWith($expectedPrefix, $second);
        $this->assertNotSame($first, $second, 'Each batch number must be unique within the same month.');
        $this->assertMatchesRegularExpression('/^BATCH-\d{6}-\d{4}$/', $first);
    }

    public function test_shipment_lot_format(): void
    {
        $svc = $this->svc();
        $first = $svc->generate('shipment_lot');
        $second = $svc->generate('shipment_lot');

        $now = now();
        $expectedPrefix = sprintf('LOT-%04d%02d-', $now->year, $now->month);

        $this->assertStringStartsWith($expectedPrefix, $first);
        $this->assertStringStartsWith($expectedPrefix, $second);
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^LOT-\d{6}-\d{4}$/', $first);
    }

    public function test_known_types_includes_traceability_types(): void
    {
        $known = $this->svc()->knownTypes();
        $this->assertContains('production_batch', $known);
        $this->assertContains('shipment_lot', $known);
    }
}
