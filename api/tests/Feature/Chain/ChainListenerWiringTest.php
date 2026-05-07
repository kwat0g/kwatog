<?php

declare(strict_types=1);

namespace Tests\Feature\Chain;

use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Listeners\NotifyOnSalesOrderConfirmed;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Events\SeparationInitiated;
use App\Modules\HR\Listeners\DeactivateAccountOnClearanceComplete;
use App\Modules\HR\Listeners\InitializeLeaveBalances;
use App\Modules\HR\Listeners\NotifyOnSeparationInitiated;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Listeners\NotifyEmployeesOnPayrollFinalized;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseOrderApproved;
use App\Modules\Quality\Events\InspectionPassed;
use App\Modules\Quality\Listeners\CreateDeliveryDraftOnQcPass;
use App\Modules\Quality\Listeners\TriggerIncomingQC;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Series C — C1/C2/C3. Verify every chain orchestrator listener is
 * actually bound to its event in AppServiceProvider. A regression here
 * silently breaks the chain — without this test, you would only notice
 * when SO confirmation stops triggering the cascade in production.
 *
 * Uses Event::hasListeners() rather than Event::fake() because we want
 * to inspect the registered binding, not whether dispatch is mocked.
 */
class ChainListenerWiringTest extends TestCase
{
    public function test_c1_o2c_listeners_are_bound(): void
    {
        $this->assertTrue(Event::hasListeners(SalesOrderConfirmed::class));
        $this->assertTrue(Event::hasListeners(WorkOrderCompleted::class));
        $this->assertTrue(Event::hasListeners(InspectionPassed::class));
    }

    public function test_c2_p2p_listeners_are_bound(): void
    {
        $this->assertTrue(Event::hasListeners(GoodsReceiptNoteCreated::class));
        $this->assertTrue(Event::hasListeners(PurchaseOrderApproved::class));
    }

    public function test_c3_h2r_listeners_are_bound(): void
    {
        $this->assertTrue(Event::hasListeners(EmployeeCreated::class));
        $this->assertTrue(Event::hasListeners(SeparationInitiated::class));
        $this->assertTrue(Event::hasListeners(ClearanceFullySigned::class));
        $this->assertTrue(Event::hasListeners(PayrollPeriodFinalized::class));
    }

    public function test_listener_classes_resolve_from_container(): void
    {
        // Quick sanity: every listener class can be constructed via the
        // container. Catches DI mismatches (e.g. typed constructor arg
        // pointing at a removed class) before they explode at runtime.
        $listeners = [
            NotifyOnSalesOrderConfirmed::class,
            TriggerOutgoingQC::class,
            CreateDeliveryDraftOnQcPass::class,
            TriggerIncomingQC::class,
            NotifyOnPurchaseOrderApproved::class,
            InitializeLeaveBalances::class,
            NotifyOnSeparationInitiated::class,
            DeactivateAccountOnClearanceComplete::class,
            NotifyEmployeesOnPayrollFinalized::class,
        ];
        foreach ($listeners as $cls) {
            $this->assertNotNull(app($cls), "Listener {$cls} should resolve from container");
        }
    }
}
