<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-4 — Chain qc_outgoing tile must reflect real Inspection state.
 *
 * Asserts SalesOrderService::chain() derives the QC Outgoing step from the
 * latest outgoing Inspection joined via WO → SO. Four states are covered:
 *   pending — no outgoing inspection yet
 *   active  — latest is in_progress (date = started_at)
 *   done    — latest is passed (date = completed_at)
 *   rejected — latest is failed (date = completed_at)
 *
 * The "latest" tie-breaker is the inspection's primary key; this matches the
 * service's `orderByDesc('i.id')` selection.
 */
class SalesOrderChainStageTest extends TestCase
{
    use RefreshDatabase;

    private SalesOrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->service = app(SalesOrderService::class);
    }

    public function test_qc_outgoing_pending_when_no_inspection_exists(): void
    {
        $so = $this->makeSo(SalesOrderStatus::InProduction);

        $step = $this->qcOutgoingStep($so);

        $this->assertSame('pending', $step['state']);
        $this->assertNull($step['date']);
    }

    public function test_qc_outgoing_active_when_latest_inspection_in_progress(): void
    {
        [$so, $wo] = $this->makeSoWithWo();

        $startedAt = now()->subHour();
        $this->makeInspection($wo, InspectionStatus::InProgress, [
            'started_at' => $startedAt,
        ]);

        $step = $this->qcOutgoingStep($so);

        $this->assertSame('active', $step['state']);
        $this->assertSame($startedAt->toDateString(), $step['date']);
    }

    public function test_qc_outgoing_done_when_latest_inspection_passed(): void
    {
        [$so, $wo] = $this->makeSoWithWo();

        $startedAt   = now()->subHours(2);
        $completedAt = now()->subMinutes(5);
        $this->makeInspection($wo, InspectionStatus::Passed, [
            'started_at'   => $startedAt,
            'completed_at' => $completedAt,
        ]);

        $step = $this->qcOutgoingStep($so);

        $this->assertSame('done', $step['state']);
        $this->assertSame($completedAt->toDateString(), $step['date']);
    }

    public function test_qc_outgoing_rejected_when_latest_inspection_failed(): void
    {
        [$so, $wo] = $this->makeSoWithWo();

        $startedAt   = now()->subHours(2);
        $completedAt = now()->subMinutes(5);
        $this->makeInspection($wo, InspectionStatus::Failed, [
            'started_at'   => $startedAt,
            'completed_at' => $completedAt,
        ]);

        $step = $this->qcOutgoingStep($so);

        $this->assertSame('rejected', $step['state']);
        $this->assertSame($completedAt->toDateString(), $step['date']);
    }

    // ─── Delivery stage: coverage, not status ──────────────────────────────────

    public function test_delivery_stage_stays_active_for_partially_delivered_invoiced_order(): void
    {
        // finalize() promotes the SO on the FIRST finalized invoice, so a
        // partly shipped order reads `invoiced`. Keyed off status alone, the
        // Delivered tile flipped to done and hid the goods still owed.
        $so = $this->makeSo(SalesOrderStatus::Invoiced);
        $this->makeLine($so, quantity: 100, delivered: 40);
        $so->forceFill([
            'partially_delivered_at' => now()->subDay(),
            'invoiced_at' => now(),
        ])->save();

        $step = $this->deliveryStep($so);

        $this->assertSame('active', $step['state']);
        $this->assertSame(now()->subDay()->toDateString(), $step['date']);
    }

    public function test_delivery_stage_done_when_fully_delivered(): void
    {
        $so = $this->makeSo(SalesOrderStatus::Invoiced);
        $this->makeLine($so, quantity: 100, delivered: 100);
        $so->forceFill(['delivered_at' => now()->subDay()])->save();

        $this->assertSame('done', $this->deliveryStep($so)['state']);
    }

    public function test_delivery_stage_pending_for_advance_invoiced_order_with_no_delivery(): void
    {
        $so = $this->makeSo(SalesOrderStatus::Invoiced);
        $this->makeLine($so, quantity: 100, delivered: 0);

        $step = $this->deliveryStep($so);

        $this->assertSame('pending', $step['state']);
        $this->assertNull($step['date']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Pluck the delivered step out of the chain payload.
     *
     * @return array{key: string, label: string, date: ?string, state: string}
     */
    private function deliveryStep(SalesOrder $so): array
    {
        foreach ($this->service->chain($so) as $step) {
            if ($step['key'] === 'delivered') {
                return $step;
            }
        }
        $this->fail('delivered step not found in chain payload.');
    }

    private function makeLine(SalesOrder $so, int $quantity, int $delivered): void
    {
        $product = Product::create([
            'part_number'     => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name'            => 'Line Product '.uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '10.00',
            'is_active'       => true,
        ]);

        SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'quantity'           => $quantity,
            'unit_price'         => '10.00',
            'total'              => number_format($quantity * 10, 2, '.', ''),
            'quantity_delivered' => $delivered,
            'delivery_date'      => now()->addDays(7)->toDateString(),
        ]);
    }

    /**
     * Pluck the qc_outgoing step out of the chain payload.
     *
     * @return array{key: string, label: string, date: ?string, state: string}
     */
    private function qcOutgoingStep(SalesOrder $so): array
    {
        $chain = $this->service->chain($so);
        foreach ($chain as $step) {
            if ($step['key'] === 'qc_outgoing') {
                return $step;
            }
        }
        $this->fail('qc_outgoing step not found in chain payload.');
    }

    private function makeSo(SalesOrderStatus $status): SalesOrder
    {
        $customer = Customer::create([
            'name'               => 'Cust ' . uniqid(),
            'is_active'          => true,
            'payment_terms_days' => 30,
        ]);

        $role = Role::firstOrCreate(['slug' => 'h4_test'], ['name' => 'H4 Test']);
        $user = User::factory()->create(['role_id' => $role->id]);

        return SalesOrder::create([
            'so_number'    => 'SO-H4-' . substr(uniqid(), -10),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '0.00',
            'vat_amount'   => '0.00',
            'total_amount' => '0.00',
            'status'       => $status->value,
            'created_by'   => $user->id,
        ]);
    }

    /**
     * @return array{0: SalesOrder, 1: WorkOrder}
     */
    private function makeSoWithWo(): array
    {
        $so = $this->makeSo(SalesOrderStatus::InProduction);

        $product = Product::create([
            'part_number'     => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name'            => 'Wiper Bushing ' . uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '50.00',
            'is_active'       => true,
        ]);

        $wo = WorkOrder::create([
            'wo_number'         => 'WO-H4-' . substr(uniqid(), -8),
            'product_id'        => $product->id,
            'sales_order_id'    => $so->id,
            'quantity_target'   => 100,
            'quantity_produced' => 100,
            'quantity_good'     => 98,
            'quantity_rejected' => 2,
            'planned_start'     => now()->subDay(),
            'planned_end'       => now(),
            'status'            => 'completed',
            'created_by'        => $so->created_by,
        ]);

        return [$so, $wo];
    }

    /**
     * Insert an outgoing Inspection row directly — bypasses InspectionService
     * to keep the test focused on the chain-derivation query.
     */
    private function makeInspection(WorkOrder $wo, InspectionStatus $status, array $overrides = []): Inspection
    {
        return Inspection::create(array_merge([
            'inspection_number' => 'QC-H4-' . substr(uniqid(), -8),
            'stage'             => InspectionStage::Outgoing->value,
            'status'            => $status->value,
            'product_id'        => $wo->product_id,
            'entity_type'       => InspectionEntityType::WorkOrder->value,
            'entity_id'         => $wo->id,
            'batch_quantity'    => 100,
            'sample_size'       => 13,
            'accept_count'      => 0,
            'reject_count'      => 1,
            'defect_count'      => 0,
        ], $overrides));
    }
}
