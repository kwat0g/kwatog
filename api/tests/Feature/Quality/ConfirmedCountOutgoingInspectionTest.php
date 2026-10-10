<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionPieceReading;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\WorkOrderFinalInspectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ConfirmedCountOutgoingInspectionTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;
    private User $checker;
    private WorkOrder $wo;
    private string $url;
    private array $group;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        config(['inspection.finish_first_wo_enabled' => true, 'inspection.outgoing_confirmed_checks_enabled' => true]);
        $this->maker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $this->checker = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        $this->wo = WorkOrder::factory()->create(['product_id' => $product->id, 'created_by' => $this->maker->id,
            'quantity_target' => 500, 'quantity_produced' => 500, 'quantity_good' => 500, 'status' => 'completed']);
        WorkOrderOutput::create(['work_order_id' => $this->wo->id, 'recorded_by' => $this->maker->id,
            'recorded_at' => now(), 'good_count' => 500, 'reject_count' => 0, 'batch_code' => 'CONFIRMED-500']);
        $spec = InspectionSpec::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Surface',
            'parameter_type' => 'visual', 'acceptance_criterion' => 'No cracks', 'is_critical' => false]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Length',
            'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm', 'nominal_value' => '100.0000',
            'tolerance_min' => '99.5000', 'tolerance_max' => '100.5000', 'is_critical' => true]);
        app(WorkOrderFinalInspectionService::class)->ensureForCompletedWorkOrder($this->wo, $this->maker);
        $this->url = '/api/v1/quality/work-orders/'.$this->wo->hash_id.'/final-inspection';
        $this->group = $this->actingAs($this->maker)->getJson($this->url)->assertSuccessful()->json('data');
    }

    public function test_new_method_pins_targets_without_creating_numeric_readings(): void
    {
        $this->assertSame(3, $this->group['batches'][0]['counted_capture']['version']);
        $this->assertSame('confirmed_checks', $this->group['batches'][0]['counted_capture']['evidence_method']);
        $this->assertSame(500, $this->group['batches'][0]['counted_capture']['required_quantity']);
        $this->assertSame(0, $this->group['batches'][0]['missing_readings']);
        $this->assertSame(0, InspectionPieceReading::count());
        $inspection = Inspection::findOrFail(app('hashids')->decode($this->group['batches'][0]['id'])[0]);
        $this->assertSame('100.0000', $inspection->measurements()->where('parameter_name', 'Length')->value('nominal_value'));
        $this->assertSame(1, app(\App\Modules\Quality\Services\InspectionService::class)->statusCounts([])['to_inspect']);
    }

    public function test_zero_defects_needs_full_check_confirmation_and_independent_review(): void
    {
        $entry = $this->entry();
        unset($entry['checks_confirmed']);
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => [$entry]])->assertUnprocessable();
        $this->assertNull(Inspection::firstOrFail()->coverage_confirmed_at);
        config(['inspection.outgoing_confirmed_checks_enabled' => false]);
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => [$this->entry()]])
            ->assertSuccessful()->assertJsonPath('data.state', 'awaiting_review')->assertJsonPath('data.accepted_quantity', 0);
        $this->group = $this->getJson($this->url)->json('data');
        $this->postJson($this->url.'/review', ['manifest' => $this->group['manifest']])->assertForbidden();
        $this->actingAs($this->checker)->postJson($this->url.'/review', ['manifest' => $this->group['manifest']])
            ->assertSuccessful()->assertJsonPath('data.state', 'released')->assertJsonPath('data.accepted_quantity', 500);
        $this->assertSame(0, InspectionPieceReading::count());
    }

    public function test_five_described_defects_fail_and_preserve_primary_check_reference(): void
    {
        $surface = collect($this->group['batches'][0]['measurements'])->firstWhere('parameter_name', 'Surface');
        $pieces = array_map(fn ($number) => ['piece_number' => $number, 'problem_description' => 'Scratch', 'requirement_id' => $surface['id']], range(1, 5));
        $this->save(5, $pieces)->assertSuccessful()->assertJsonPath('data.defect_page.pieces.0.requirement_id', $surface['id']);
        $this->group = $this->getJson($this->url)->json('data');
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => [$this->entry()]])
            ->assertSuccessful()->assertJsonPath('data.batches.0.proposed_result', 'failed');
        $this->assertFalse(Inspection::firstOrFail()->critical_defect);
        $this->group = $this->getJson($this->url)->json('data');
        $this->actingAs($this->checker)->postJson($this->url.'/review', ['manifest' => $this->group['manifest'], 'remarks' => 'Five scratched pieces confirmed. Segregate for disposition.'])
            ->assertSuccessful()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.accepted_quantity', 0);
        $this->assertNotNull(Inspection::firstOrFail()->ncr);
        $inspection = Inspection::firstOrFail();
        app(\App\Modules\Quality\Services\NcrService::class)->setDisposition(
            $inspection->ncr, 'rework', 'Surface damage.', 'Repair five segregated pieces.', $this->checker, '495', [
                'performed_by' => $this->maker->id, 'inspected_quantity' => '500', 'evidence_reference' => 'SEGREGATION-500',
                'recovery_passed' => true, 'defects_segregated' => true,
            ],
        );
        $this->assertSame(495, (int) $inspection->fresh()->accepted_quantity);
        $this->assertSame(500, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame('failed', $inspection->fresh()->status->value);
    }

    public function test_unclassified_defects_require_conservative_critical_review(): void
    {
        $this->save(1, [['piece_number' => 1, 'problem_description' => 'Unknown defect', 'requirement_id' => null]])->assertSuccessful();
        $this->group = $this->getJson($this->url)->json('data');
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => [$this->entry()]])->assertSuccessful();
        $this->assertTrue(Inspection::firstOrFail()->critical_defect);
    }

    public function test_target_values_cannot_be_written_as_confirmed_measurements(): void
    {
        $body = $this->saveBody(0, []);
        $body['readings'] = [['piece_number' => 1, 'requirement_id' => $this->group['batches'][0]['measurements'][1]['id'], 'measured_value' => '100.0000']];
        $this->patchJson($this->url.'/evidence', $body)->assertUnprocessable();
        $this->assertSame(0, InspectionPieceReading::count());
    }

    public function test_critical_requirement_failure_is_not_downgraded(): void
    {
        $length = collect($this->group['batches'][0]['measurements'])->firstWhere('parameter_name', 'Length');
        $this->save(1, [['piece_number' => 1, 'problem_description' => 'Length outside tolerance', 'requirement_id' => $length['id']]])->assertSuccessful();
        $this->group = $this->getJson($this->url)->json('data');
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => [$this->entry()]])->assertSuccessful();
        $this->assertTrue(Inspection::firstOrFail()->critical_defect);
        $this->assertSame('failed', Inspection::firstOrFail()->proposed_result->value);
    }

    public function test_blank_problem_cannot_be_submitted_and_schema_cannot_be_erased(): void
    {
        $this->save(1, [['piece_number' => 1, 'problem_description' => ' ']])->assertSuccessful();
        $this->group = $this->getJson($this->url)->json('data');
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => [$this->entry()]])->assertUnprocessable();
        $this->assertNull(Inspection::firstOrFail()->coverage_confirmed_at);
        $migration = require database_path('migrations/2026_10_09_000100_add_outgoing_defect_requirement_reference.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Preserve confirmed-check inspection evidence');
        $migration->down();
    }

    public function test_stale_or_invalid_defect_evidence_does_not_partially_save(): void
    {
        $this->save(501, [])->assertUnprocessable();
        $this->save(1, [['piece_number' => 1, 'problem_description' => 'Crack', 'requirement_id' => 'foreign']])->assertUnprocessable();
        $this->save(2, [['piece_number' => 1, 'problem_description' => 'Crack'], ['piece_number' => 1, 'problem_description' => 'Dent']])->assertUnprocessable();
        $this->save(1, [['piece_number' => 1, 'problem_description' => str_repeat('x', 501)]])->assertUnprocessable();
        $this->assertSame(0, Inspection::firstOrFail()->result_version);
        $this->save(0, [])->assertSuccessful();
        $this->save(0, [])->assertUnprocessable();
    }

    public function test_all_five_hundred_defective_pieces_release_nothing(): void
    {
        $surface = collect($this->group['batches'][0]['measurements'])->firstWhere('parameter_name', 'Surface');
        foreach (array_chunk(range(1, 500), 50) as $numbers) {
            $this->save(500, array_map(fn ($number) => ['piece_number' => $number,
                'problem_description' => 'Surface scratch', 'requirement_id' => $surface['id']], $numbers))->assertSuccessful();
            $this->group = $this->getJson($this->url)->json('data');
        }
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => [$this->entry()]])->assertSuccessful();
        $this->group = $this->getJson($this->url)->json('data');
        $this->actingAs($this->checker)->postJson($this->url.'/review', ['manifest' => $this->group['manifest'],
            'remarks' => 'All pieces damaged; keep the entire batch held.'])->assertSuccessful()->assertJsonPath('data.accepted_quantity', 0);
        $this->assertSame(500, Inspection::firstOrFail()->defectivePieces()->count());
        $this->assertSame('failed', Inspection::firstOrFail()->status->value);
    }

    public function test_mixed_child_confirmations_submit_atomically(): void
    {
        WorkOrderOutput::create(['work_order_id' => $this->wo->id, 'recorded_by' => $this->maker->id,
            'recorded_at' => now(), 'good_count' => 1, 'reject_count' => 0, 'batch_code' => 'CONFIRMED-CHILD']);
        app(WorkOrderFinalInspectionService::class)->ensureForCompletedWorkOrder($this->wo, $this->maker);
        $this->group = $this->getJson($this->url)->json('data');
        $entries = array_map(fn ($row) => ['inspection_id' => $row['id'], 'coverage_confirmed' => true,
            'checks_confirmed' => true, 'inspected_quantity' => $row['batch_quantity']], $this->group['batches']);
        $entries[1]['checks_confirmed'] = false;
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => $entries])->assertUnprocessable();
        $this->assertSame(0, Inspection::whereNotNull('coverage_confirmed_at')->count());
        $this->assertSame(0, Inspection::where('status', 'awaiting_review')->count());
        $entries[1]['checks_confirmed'] = true;
        $this->postJson($this->url.'/submit', ['manifest' => $this->group['manifest'], 'batches' => $entries])->assertSuccessful();
        $this->group = $this->getJson($this->url)->json('data');
        $this->actingAs($this->checker)->postJson($this->url.'/review', ['manifest' => $this->group['manifest']])
            ->assertSuccessful()->assertJsonPath('data.accepted_quantity', 501);
        $this->assertSame(2, Inspection::where('status', 'passed')->count());
    }

    private function entry(): array
    {
        return ['inspection_id' => $this->group['batches'][0]['id'], 'coverage_confirmed' => true, 'checks_confirmed' => true, 'inspected_quantity' => 500];
    }

    private function saveBody(int $count, array $pieces): array
    {
        return ['manifest' => $this->group['manifest'], 'inspection_id' => $this->group['batches'][0]['id'],
            'expected_result_version' => $this->group['batches'][0]['result_version'], 'defective_quantity' => $count, 'pieces' => $pieces, 'readings' => []];
    }

    private function save(int $count, array $pieces): \Illuminate\Testing\TestResponse
    {
        return $this->patchJson($this->url.'/evidence', $this->saveBody($count, $pieces));
    }
}
