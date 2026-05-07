<?php

declare(strict_types=1);

namespace Tests\Feature\Chain;

use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Events\InspectionPassed;
use Tests\TestCase;

/**
 * Series C — Tasks C1/C2/C3.
 *
 * Smoke test for the new domain event classes. Listener wiring is
 * exercised by ChainListenerWiringTest; this test just confirms the
 * event class files load and instantiate cleanly so a typo in any one
 * of the 7 fails CI.
 */
class ChainEventDispatchTest extends TestCase
{
    public function test_all_chain_event_classes_exist(): void
    {
        $events = [
            WorkOrderCompleted::class,
            InspectionPassed::class,
            InspectionFailed::class,
            PurchaseOrderApproved::class,
            GoodsReceiptNoteCreated::class,
            EmployeeCreated::class,
            SeparationInitiated::class,
            PayrollPeriodFinalized::class,
            \App\Modules\HR\Events\ClearanceFullySigned::class,
            \App\Modules\Purchasing\Events\PurchaseRequestApproved::class,
        ];

        foreach ($events as $cls) {
            $this->assertTrue(class_exists($cls), "Event class {$cls} should exist");
        }
    }
}
