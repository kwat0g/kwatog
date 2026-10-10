<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class NcrOutputListTest extends TestCase
{
    use RefreshDatabase;

    public function test_qc_can_list_and_open_ncrs_with_production_output_under_strict_loading(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $qc = User::factory()->withRole('qc_inspector')->create();
        $workOrder = WorkOrder::factory()->create(['created_by' => $qc->id]);
        $reports = [];
        foreach ([false, true] as $historical) {
            $output = WorkOrderOutput::create([
                'work_order_id' => $workOrder->id,
                'recorded_by' => $qc->id,
                'recorded_at' => now(),
                'good_count' => 500,
                'reject_count' => 0,
                'batch_code' => 'NCR-LIST-'.(int) $historical,
                'production_started_at' => $historical ? now()->subHours(2) : null,
            ]);
            $inspection = Inspection::create([
                'inspection_number' => 'NCR-LIST-QC-'.(int) $historical,
                'stage' => 'outgoing',
                'status' => 'failed',
                'entity_type' => 'work_order',
                'entity_id' => $workOrder->id,
                'work_order_output_id' => $output->id,
                'product_id' => $workOrder->product_id,
                'batch_quantity' => 500,
                'sample_size' => 500,
                'inspector_id' => $qc->id,
                'counted_capture' => ['version' => 3, 'workflow' => 'finish_first_v1', 'evidence_method' => 'confirmed_checks'],
            ]);
            $inspection->forceFill(['sample_defect_count' => 2])->save();
            $reports[] = NonConformanceReport::factory()->create([
                'inspection_id' => $inspection->id,
                'product_id' => $workOrder->product_id,
                'created_by' => $qc->id,
                'affected_quantity' => 500,
            ]);
        }

        Model::preventLazyLoading();
        try {
            $data = $this->actingAs($qc)->getJson('/api/v1/quality/ncrs')->assertOk()->json('data');
            $this->assertCount(2, $data);
            foreach ($reports as $index => $report) {
                $row = collect($data)->firstWhere('id', $report->hash_id);
                $this->assertSame((bool) $index, $row['historical_held']);
                $this->assertSame(500, $row['affected_quantity']);
                $this->assertSame(2, $row['inspection']['sample_defect_count']);
                $this->assertSame($qc->hash_id, $row['inspection']['inspector']['id']);
                $this->assertTrue($row['full_piece_inspection']);
                $this->assertTrue($row['is_outgoing_recovery']);

                $detail = app(NcrService::class)->show($report->fresh());
                $detail->inspection->preventsLazyLoading = true;
                $detailData = (new NcrResource($detail))->resolve(Request::create('/'));
                $this->assertSame((bool) $index, $detailData['historical_held']);
                $this->assertSame($qc->name, $detailData['inspection']['inspector']['name']);
            }
        } finally {
            Model::preventLazyLoading(false);
        }
    }
}
