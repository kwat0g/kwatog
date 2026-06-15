<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NcrAutoReworkWoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function ncrFromOutgoingInspection(): NonConformanceReport
    {
        $product = Product::factory()->create();
        $insp = Inspection::create([
            'inspection_number' => 'QC-T-'.substr(uniqid(), -8),
            'stage'             => InspectionStage::Outgoing->value,
            'product_id'        => $product->id,
            'batch_quantity'    => 100,
            'sample_size'       => 5,
            'accept_count'      => 0,
            'defect_count'      => 5,
            'status'            => InspectionStatus::Failed->value,
        ]);
        $ncr = NonConformanceReport::factory()->create([
            'inspection_id'      => $insp->id,
            'product_id'         => $product->id,
            'affected_quantity'  => 50,
        ]);
        $ncr->forceFill(['disposition' => NcrDisposition::Rework->value])->save();
        return $ncr;
    }

    private function addBothActions(NonConformanceReport $ncr, User $by): void
    {
        foreach ([NcrActionType::Corrective, NcrActionType::Preventive] as $t) {
            NcrAction::create([
                'ncr_id' => $ncr->id, 'action_type' => $t->value,
                'description' => 'x', 'performed_by' => $by->id, 'performed_at' => now(),
            ]);
        }
    }

    public function test_rework_disposition_outgoing_creates_rework_wo(): void
    {
        $by = $this->user();
        $ncr = $this->ncrFromOutgoingInspection();
        $this->addBothActions($ncr, $by);

        $closed = app(NcrService::class)->close($ncr, $by);

        $this->assertNotNull($closed->rework_work_order_id);
        $wo = WorkOrder::find($closed->rework_work_order_id);
        $this->assertNotNull($wo);
        $this->assertSame((int) $ncr->id, (int) $wo->parent_ncr_id);
        $this->assertSame(50, (int) $wo->quantity_target);
        $this->assertSame(7, (int) $wo->priority);
    }

    public function test_rework_disposition_in_process_does_not_create_wo(): void
    {
        $by = $this->user();
        $ncr = $this->ncrFromOutgoingInspection();
        $this->addBothActions($ncr, $by);
        // Flip parent inspection stage to in_process — auto-rework should skip.
        Inspection::query()->whereKey($ncr->inspection_id)
            ->update(['stage' => InspectionStage::InProcess->value]);

        $closed = app(NcrService::class)->close($ncr, $by);

        $this->assertNull($closed->rework_work_order_id);
    }

    public function test_scrap_disposition_unaffected_by_t3_1_b(): void
    {
        $by = $this->user();
        $ncr = $this->ncrFromOutgoingInspection();
        $ncr->forceFill(['disposition' => NcrDisposition::Scrap->value])->save();
        $this->addBothActions($ncr, $by);

        $closed = app(NcrService::class)->close($ncr, $by);

        // Existing Scrap path still creates a replacement WO; rework field stays null.
        $this->assertNull($closed->rework_work_order_id);
        $this->assertNotNull($closed->replacement_work_order_id);
    }
}
