<?php

declare(strict_types=1);

require_once '/var/www/tests/Feature/Quality/WorkOrderQcConnectionTest.php';

/** Disposable audit probe for cancellation after physical production. */
final class QcCancelledProductionProbeTest extends \Tests\Feature\Quality\WorkOrderQcConnectionTest
{
    public function test_probe_cancelled_produced_work_order_is_held_but_still_counts_as_mrp_commitment(): void
    {
        $parent = parent::class;
        $wo = (new \ReflectionProperty($parent, 'wo'))->getValue($this);
        $line = (new \ReflectionProperty($parent, 'line'))->getValue($this);
        $inspection = (new \ReflectionProperty($parent, 'inspection'))->getValue($this);
        $wo->forceFill(['status' => 'paused'])->save();
        $cancelled = app(\App\Modules\Production\Services\WorkOrderService::class)->cancel($wo, 'Audit cancellation after output');
        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertFalse($inspection->fresh()->canReleaseOutgoing());
        $remaining = (new \ReflectionMethod(\App\Modules\MRP\Services\MrpEngineService::class, 'remainingToProduce'))
            ->invoke(app(\App\Modules\MRP\Services\MrpEngineService::class), $line);
        $this->assertSame(0.0, $remaining);
    }
}
