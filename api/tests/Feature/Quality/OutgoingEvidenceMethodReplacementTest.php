<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\WorkOrderFinalInspectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutgoingEvidenceMethodReplacementTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private WorkOrder $wo;
    private Inspection $original;
    private string $url;
    private array $body;

    protected function setUp(): void
    {
        parent::setUp(); Queue::fake(); $this->seed(RolePermissionSeeder::class);
        config(['inspection.finish_first_wo_enabled' => true, 'inspection.outgoing_confirmed_checks_enabled' => false]);
        $this->actor = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        $this->wo = WorkOrder::factory()->create(['product_id' => $product->id, 'created_by' => $this->actor->id,
            'status' => 'completed', 'quantity_target' => 1, 'quantity_good' => 1, 'quantity_produced' => 1]);
        WorkOrderOutput::create(['work_order_id' => $this->wo->id, 'recorded_by' => $this->actor->id, 'recorded_at' => now(), 'good_count' => 1, 'reject_count' => 0, 'batch_code' => 'ORIGINAL-ONE']);
        $spec = InspectionSpec::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->actor->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Surface', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks']);
        $service = app(WorkOrderFinalInspectionService::class);
        $this->original = $service->ensureForCompletedWorkOrder($this->wo, $this->actor)->first();
        $group = $service->show($this->wo, $this->actor);
        $this->body = ['manifest' => $group['manifest'], 'reason' => 'Use approved confirmed-check method for untouched draft.'];
        $this->url = '/api/v1/quality/work-orders/'.$this->wo->hash_id.'/final-inspection/replace-method';
        config(['inspection.outgoing_confirmed_checks_enabled' => true]);
    }

    public function test_untouched_replacement_preserves_original_revision_and_retries_once(): void
    {
        $this->actingAs($this->actor)->postJson($this->url, $this->body)->assertSuccessful()->assertJsonPath('data.batches.0.counted_capture.version', 3);
        $this->postJson($this->url, $this->body)->assertSuccessful();
        $this->assertSame(2, Inspection::count());
        $this->assertSame('cancelled', $this->original->fresh()->status->value);
        $successor = Inspection::where('replaces_inspection_id', $this->original->id)->firstOrFail();
        $this->assertSame($this->original->inspection_spec_revision_id, $successor->inspection_spec_revision_id);
        $this->assertSame(2, $this->original->fresh()->counted_capture['version']);
        $this->assertSame($this->original->work_order_output_id, $successor->work_order_output_id);
    }

    public function test_recorded_draft_cannot_be_replaced(): void
    {
        $this->original->resultAuthors()->create(['user_id' => $this->actor->id]);
        $this->actingAs($this->actor)->postJson($this->url, $this->body)->assertUnprocessable();
        $this->assertSame(1, Inspection::count());
        $this->assertSame('draft', $this->original->fresh()->status->value);
    }

    public function test_stale_and_unauthorized_requests_cannot_replace_evidence(): void
    {
        $invalid = $this->body; $invalid['manifest'][0]['result_version'] = 1;
        $this->actingAs($this->actor)->postJson($this->url, $invalid)->assertUnprocessable();
        $worker = User::factory()->withRole('employee')->create(['is_active' => true]);
        $this->actingAs($worker)->postJson($this->url, $this->body)->assertForbidden();
        $this->assertSame(1, Inspection::count());
    }

    public function test_scalar_result_evidence_cannot_be_replaced(): void
    {
        $this->original->forceFill(['proposed_result' => 'passed'])->save();
        $this->actingAs($this->actor)->postJson($this->url, $this->body)->assertUnprocessable();
        $this->assertSame(1, Inspection::count());
        $this->assertSame('draft', $this->original->fresh()->status->value);
    }

    public function test_direct_replacement_rechecks_source_and_keeps_original_on_failure(): void
    {
        $this->wo->forceFill(['status' => 'in_progress'])->save();
        try {
            app(\App\Modules\Quality\Services\InspectionService::class)->createConfirmedReplacement(
                $this->original, $this->wo, $this->actor, 'Switch untouched draft.',
            );
            $this->fail('An unfinished source must not switch methods.');
        } catch (\App\Common\Exceptions\BusinessRuleException $exception) {
            $this->assertStringContainsString('unchanged completed production', $exception->getMessage());
        }
        $this->assertSame(1, Inspection::count());
        $this->assertSame('draft', $this->original->fresh()->status->value);
    }

    public function test_saved_numeric_reading_and_changed_output_refuse_replacement(): void
    {
        $requirement = $this->original->measurements()->firstOrFail();
        $reading = $this->original->pieceReadings()->create(['piece_number' => 1,
            'requirement_id' => $requirement->id, 'measured_value' => '1.0000', 'is_pass' => true,
            'recorded_by' => $this->actor->id]);
        $this->actingAs($this->actor)->postJson($this->url, $this->body)->assertUnprocessable();
        $this->assertSame(1, $this->original->pieceReadings()->count());
        $reading->delete();
        $this->original->workOrderOutput->forceFill(['good_count' => 2])->save();
        $this->postJson($this->url, $this->body)->assertUnprocessable();
        $this->assertSame(1, Inspection::count());
        $this->assertSame('draft', $this->original->fresh()->status->value);
    }
}
