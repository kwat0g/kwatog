<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistEvidenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_transition_round_trips_without_altering_legacy_evidence(): void
    {
        $product = Product::factory()->create();
        $user = User::factory()->create();
        $spec = InspectionSpec::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true, 'created_by' => $user->id]);
        $item = InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Diameter',
            'parameter_type' => 'dimensional', 'nominal_value' => '10.0000', 'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000']);
        $spec->ensureCurrentRevision();
        $inspection = Inspection::create(['inspection_number' => 'QC-MIGRATION-PROOF', 'stage' => 'in_process',
            'status' => 'passed', 'product_id' => $product->id, 'batch_quantity' => 100, 'sample_size' => 5, 'inspector_id' => $user->id]);
        $row = InspectionMeasurement::create(['inspection_id' => $inspection->id, 'sample_index' => 1,
            'parameter_name' => 'Diameter', 'parameter_type' => 'dimensional', 'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000', 'measured_value' => '10.0200', 'is_pass' => true]);
        $migration = require database_path('migrations/2026_10_06_200000_add_checklist_evidence_contract.php');
        $migration->down();
        $migration->up();
        $this->assertSame('measurement', $inspection->fresh()->capture_method);
        $this->assertSame('passed', $inspection->fresh()->status->value);
        $this->assertSame('10.0200', $row->fresh()->measured_value);
        $this->assertTrue($row->fresh()->is_pass);
        $this->assertSame('9.9000', $item->fresh()->tolerance_min);
        $this->assertSame('measurement', $spec->fresh()->currentRevision->capture_method);
    }

    public function test_rollback_refuses_to_destroy_checklist_evidence(): void
    {
        Inspection::create(['inspection_number' => 'QC-ROLLBACK-PROOF', 'stage' => 'in_process', 'status' => 'draft',
            'capture_method' => 'checklist', 'source_quantity' => '100.000', 'source_uom_code' => 'PCS',
            'sampling_basis' => 'piece', 'batch_quantity' => 100, 'sample_size' => 5]);
        $migration = require database_path('migrations/2026_10_06_200000_add_checklist_evidence_contract.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
