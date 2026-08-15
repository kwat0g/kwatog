<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P01-01 shape on P67 (NCR lifecycle): NcrService::close guards the *passed*
 * model's terminal status outside the transaction and re-creates the
 * replacement/rework WO from the stale model inside. A second concurrent close
 * of the same NCR passes the stale guard and creates a *second* rework WO.
 */
class NcrDoubleCloseRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('quality.ncr.replacement_work_order_priority', 7);
        app(SettingsService::class)->set('quality.ncr.replacement_work_order_lead_days', 1);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function reworkNcr(): NonConformanceReport
    {
        $product = Product::factory()->create();
        $insp = Inspection::create([
            'inspection_number' => 'QC-RACE-'.substr(uniqid(), -8),
            'stage'             => InspectionStage::Outgoing->value,
            'product_id'        => $product->id,
            'batch_quantity'    => 100,
            'sample_size'       => 5,
            'accept_count'      => 0,
            'defect_count'      => 5,
            'status'            => InspectionStatus::Failed->value,
        ]);
        $ncr = NonConformanceReport::factory()->create([
            'inspection_id'     => $insp->id,
            'product_id'        => $product->id,
            'affected_quantity' => 50,
        ]);
        $ncr->forceFill(['disposition' => NcrDisposition::Rework->value])->save();

        foreach ([NcrActionType::Corrective, NcrActionType::Preventive] as $type) {
            NcrAction::create([
                'ncr_id'       => $ncr->id,
                'action_type'  => $type->value,
                'description'  => 'x',
                'performed_by' => $ncr->created_by,
                'performed_at' => now(),
            ]);
        }

        return $ncr;
    }

    public function test_stale_second_close_is_blocked_and_creates_single_rework_wo(): void
    {
        $by = $this->user();
        $ncr = $this->reworkNcr();

        // Both "concurrent" closers fetched the row while it was open.
        $closerA = NonConformanceReport::find($ncr->id);
        $closerB = NonConformanceReport::find($ncr->id);

        app(NcrService::class)->close($closerA, $by);

        $this->assertSame(
            1,
            WorkOrder::query()->where('parent_ncr_id', $ncr->id)->count(),
            'Exactly one rework WO must be created for the NCR.'
        );

        try {
            app(NcrService::class)->close($closerB, $by);
            $this->fail('A stale second close must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already closed', strtolower($e->getMessage()));
        }

        $this->assertSame(
            1,
            WorkOrder::query()->where('parent_ncr_id', $ncr->id)->count(),
            'The stale close must not create a second rework WO.'
        );
        $this->assertSame(NcrStatus::Closed, $ncr->refresh()->status);
    }
}
