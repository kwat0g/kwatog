<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Listeners\TriggerInProcessQC;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O2C audit 2026-09-25 — "In-process / Outgoing QC required" opened the work
 * order, so the inspector had to find the inspection from there. The notice
 * now opens the inspection; a completion that created several outgoing
 * batches still opens the work order, which lists them all.
 */
class QcRequiredNotificationLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $this->inspector = User::factory()->create([
            'is_active' => true,
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
    }

    private function workOrder(WorkOrderStatus $status): WorkOrder
    {
        $creator = User::factory()->create(['role_id' => Role::query()->where('slug', 'production_manager')->value('id')]);
        $this->actingAs($creator);
        $product = Product::factory()->create();
        app(InspectionSpecService::class)->upsertForProduct($product->id, [
            ['parameter_name' => 'Outer diameter', 'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm',
                'nominal_value' => '10.000', 'tolerance_min' => '9.950', 'tolerance_max' => '10.050', 'is_critical' => true],
        ], $creator->id);

        return WorkOrder::factory()->create([
            'product_id' => $product->id,
            'sales_order_id' => SalesOrder::factory()->create()->id,
            'quantity_target' => 100,
            'status' => $status->value,
            'created_by' => $creator->id,
        ]);
    }

    private function link(string $type): ?string
    {
        $data = DB::table('notifications')->where('notifiable_id', $this->inspector->id)->where('type', $type)->value('data');

        return $data ? json_decode((string) $data, true)['link_to'] : null;
    }

    private function recordBatch(WorkOrder $wo, int $good): void
    {
        WorkOrderOutput::create([
            'work_order_id' => $wo->id, 'recorded_by' => $wo->created_by, 'recorded_at' => now(),
            'good_count' => $good, 'reject_count' => 0,
        ]);
    }

    public function test_in_process_notice_opens_the_inspection(): void
    {
        $wo = $this->workOrder(WorkOrderStatus::InProgress);

        app(TriggerInProcessQC::class)->handle(new WorkOrderStatusChanged($wo, 'confirmed', 'in_progress'));

        $inspection = Inspection::query()->where('entity_id', $wo->id)->where('stage', 'in_process')->firstOrFail();
        $this->assertSame("/quality/inspections/{$inspection->hash_id}", $this->link('chain.in_process_qc_required'));
    }

    public function test_outgoing_notice_opens_the_inspection_of_a_single_batch(): void
    {
        $wo = $this->workOrder(WorkOrderStatus::Completed);
        $this->recordBatch($wo, 100);

        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));

        $inspection = Inspection::query()->where('entity_id', $wo->id)->where('stage', 'outgoing')->firstOrFail();
        $this->assertSame("/quality/inspections/{$inspection->hash_id}", $this->link('chain.outgoing_qc_required'));
    }

    public function test_outgoing_notice_for_several_batches_opens_the_work_order(): void
    {
        $wo = $this->workOrder(WorkOrderStatus::Completed);
        $this->recordBatch($wo, 60);
        $this->recordBatch($wo, 40);

        app(TriggerOutgoingQC::class)->handle(new WorkOrderCompleted($wo));

        $this->assertSame(2, Inspection::query()->where('entity_id', $wo->id)->where('stage', 'outgoing')->count());
        $this->assertSame("/production/work-orders/{$wo->hash_id}", $this->link('chain.outgoing_qc_required'));
    }
}
