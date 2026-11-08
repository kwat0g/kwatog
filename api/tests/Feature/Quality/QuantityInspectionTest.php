<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuantityInspectionTest extends TestCase
{
    use RefreshDatabase;

    private function inspection(bool $piece = false, bool $incoming = false, ?string $stage = null, bool $counted = true): Inspection
    {
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->actingAs(User::factory()->withRole('qc_inspector')->create(['is_active' => true]));
        $inspection = Inspection::create([
            'inspection_number' => 'UOM-'.uniqid(), 'stage' => $stage ?? ($incoming ? 'incoming' : 'in_process'), 'status' => 'draft',
            ...($incoming ? ['entity_type' => 'grn', 'entity_id' => \App\Modules\Inventory\Models\GoodsReceiptNote::factory()->create()->id] : []),
            'product_id' => Product::factory()->create()->id, 'batch_quantity' => $piece ? 500 : 1, 'sample_size' => 1,
            'accept_count' => 0, 'reject_count' => 1, 'inspection_mode' => 'lot_checklist',
            'capture_method' => 'measurement', 'sampling_basis' => $piece ? 'piece' : 'lot',
            'source_quantity' => '500.000', 'source_uom_code' => $piece ? 'PCS' : 'kg',
            'counted_capture' => $counted ? ['version' => 1, 'required_quantity' => 1, 'result_policy' => 'zero_defects'] : null,
        ]);
        InspectionMeasurement::create(['inspection_id' => $inspection->id, 'sample_index' => 1,
            'parameter_name' => 'Moisture', 'parameter_type' => 'dimensional', 'acceptance_criterion' => 'Approved material',
            'tolerance_min' => '0', 'tolerance_max' => '0.2', 'is_critical' => false]);

        return $inspection;
    }

    public function test_decimal_kg_failure_is_saved_in_kg_and_keeps_the_source_held(): void
    {
        $inspection = $this->inspection();
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        $this->postJson($url, ['expected_result_version' => 0, 'quantity_result' => ['defective_quantity' => '500.001', 'descriptions' => ['Contaminated']]])->assertUnprocessable();
        $this->postJson($url, ['expected_result_version' => 0, 'quantity_result' => ['defective_quantity' => '2.500', 'descriptions' => ['Contaminated']]])
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed')
            ->assertJsonPath('data.quantity_result.defective_quantity', '2.500')->assertJsonPath('data.quantity_result.unit', 'kg');
        $this->assertNull($inspection->measurements()->sole()->measured_value);
        $this->assertSame(1, $inspection->fresh()->sample_defect_count);
        $this->assertSame(0, $inspection->fresh()->accepted_quantity);
    }

    public function test_explicit_zero_passes_without_fabricated_numeric_readings(): void
    {
        $inspection = $this->inspection();
        $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', ['expected_result_version' => 0,
            'quantity_result' => ['defective_quantity' => '0', 'descriptions' => []]])->assertSuccessful()->assertJsonPath('data.recorded_result', 'passed');
        $this->assertNull($inspection->measurements()->sole()->is_pass);
    }

    public function test_piece_counts_require_whole_numbers_and_one_reason_for_each_piece(): void
    {
        $inspection = $this->inspection(true);
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result';
        foreach ([['2.5', ['Cracks']], ['5', ['Cracks']], ['-1', []]] as [$quantity, $descriptions]) {
            $this->postJson($url, ['expected_result_version' => 0, 'quantity_result' => ['defective_quantity' => $quantity, 'descriptions' => $descriptions]])->assertUnprocessable();
        }
        $this->postJson($url, ['expected_result_version' => 0, 'quantity_result' => ['defective_quantity' => '5', 'descriptions' => array_fill(0, 5, 'Cracked')]])
            ->assertSuccessful()->assertJsonPath('data.recorded_result', 'failed')->assertJsonPath('data.sample_defect_count', 5);
    }

    public function test_incoming_kg_pass_can_be_independently_reviewed_after_database_round_trip(): void
    {
        $inspection = $this->inspection(false, true);
        $url = '/api/v1/quality/inspections/'.$inspection->hash_id;
        $this->postJson($url.'/batch-result', ['expected_result_version' => 0,
            'quantity_result' => ['defective_quantity' => '0', 'descriptions' => []]])
            ->assertSuccessful()->assertJsonPath('data.status', 'awaiting_review');
        $reviewer = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $this->actingAs($reviewer)->patchJson($url.'/review', ['expected_result_version' => 1, 'decision' => 'passed'])
            ->assertSuccessful()->assertJsonPath('data.status', 'passed');
        $this->assertNull($inspection->measurements()->sole()->measured_value);
    }

    public function test_every_standard_stage_accepts_simple_results_with_or_without_a_counted_plan(): void
    {
        foreach (['incoming', 'in_process', 'outgoing', 'customer_return', 'supplier_return'] as $stage) {
            foreach ([true, false] as $counted) {
                $inspection = $this->inspection(false, false, $stage, $counted);
                $this->postJson('/api/v1/quality/inspections/'.$inspection->hash_id.'/batch-result', [
                    'expected_result_version' => 0, 'quantity_result' => ['defective_quantity' => '0', 'descriptions' => []],
                ])->assertSuccessful()->assertJsonPath('data.recorded_result', 'passed');
                $this->assertNull($inspection->measurements()->sole()->measured_value);
            }
        }
    }
}
