<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Jobs\ProcessNcrRecurrenceScan;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NcrRecurrenceTest extends TestCase
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
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
    }

    private function makeNcr(int $productId, string $description, ?\Carbon\Carbon $createdAt = null): NonConformanceReport
    {
        $ncr = app(NcrService::class)->create([
            'source'             => 'inspection_fail',
            'severity'           => 'medium',
            'product_id'         => $productId,
            'defect_description' => $description,
            'affected_quantity'  => 1,
            'is_auto_generated'  => false,
        ], $this->user())->fresh();

        // RefreshDatabase keeps the test transaction open, so the production
        // after-commit dispatch may not run until teardown. Pump the durable
        // scan explicitly; if the callback already ran, the job is a no-op.
        ProcessNcrRecurrenceScan::dispatchSync($ncr->id);

        return $ncr->fresh();
    }

    private function failedInspection(Product $product, string $number): Inspection
    {
        $inspection = Inspection::create([
            'inspection_number' => $number,
            'stage'             => InspectionStage::Outgoing->value,
            'status'            => InspectionStatus::Failed->value,
            'product_id'        => $product->id,
            'batch_quantity'    => 10,
            'sample_size'       => 1,
            'accept_count'      => 0,
            'reject_count'      => 1,
            'defect_count'      => 1,
        ]);
        InspectionMeasurement::create([
            'inspection_id'  => $inspection->id,
            'sample_index'   => 1,
            'parameter_name' => 'Shaft OD',
            'parameter_type' => 'dimensional',
            'nominal_value'  => '10.00',
            'tolerance_min'  => '9.90',
            'tolerance_max'  => '10.10',
            'is_critical'    => true,
            'is_pass'        => false,
        ]);

        return $inspection->fresh(['measurements']);
    }

    public function test_first_ncr_has_no_recurrence_link(): void
    {
        $product = Product::factory()->create();
        $ncr = $this->makeNcr($product->id, 'Outer diameter out of tolerance');
        $this->assertNull($ncr->recurrence_of_ncr_id);
    }

    public function test_second_ncr_same_product_same_signature_links_to_first(): void
    {
        $product = Product::factory()->create();
        $first  = $this->makeNcr($product->id, 'Outer diameter out of tolerance');
        $second = $this->makeNcr($product->id, 'Outer diameter out of tolerance');

        $this->assertSame((int) $first->id, (int) $second->fresh()->recurrence_of_ncr_id);
    }

    public function test_equivalent_auto_inspection_failures_link_by_structured_signature(): void
    {
        $product = Product::factory()->create();
        $firstInspection = $this->failedInspection($product, 'QC-REC-001');
        $secondInspection = $this->failedInspection($product, 'QC-REC-002');
        $user = $this->user();
        $service = app(NcrService::class);

        $first = $service->create([
            'source'             => 'inspection_fail',
            'severity'           => 'high',
            'product_id'         => $product->id,
            'inspection_id'      => $firstInspection->id,
            'defect_description' => 'Automated NCR from inspection QC-REC-001.',
            'affected_quantity'  => 1,
            'is_auto_generated'  => true,
        ], $user);
        ProcessNcrRecurrenceScan::dispatchSync($first->id);

        $second = $service->create([
            'source'             => 'inspection_fail',
            'severity'           => 'high',
            'product_id'         => $product->id,
            'inspection_id'      => $secondInspection->id,
            'defect_description' => 'Automated NCR from inspection QC-REC-002.',
            'affected_quantity'  => 1,
            'is_auto_generated'  => true,
        ], $user);
        ProcessNcrRecurrenceScan::dispatchSync($second->id);

        $this->assertSame((int) $first->id, (int) $second->fresh()->recurrence_of_ncr_id);
    }

    public function test_different_product_does_not_link(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $first  = $this->makeNcr($a->id, 'Outer diameter out of tolerance');
        $second = $this->makeNcr($b->id, 'Outer diameter out of tolerance');

        $this->assertNull($second->fresh()->recurrence_of_ncr_id);
    }

    public function test_outside_30_day_window_does_not_link(): void
    {
        $product = Product::factory()->create();
        $first  = $this->makeNcr($product->id, 'Outer diameter out of tolerance');
        // Backdate the first by 31 days so the window scan misses it.
        NonConformanceReport::query()->whereKey($first->id)
            ->update(['created_at' => now()->subDays(31)]);

        $second = $this->makeNcr($product->id, 'Outer diameter out of tolerance');
        $this->assertNull($second->fresh()->recurrence_of_ncr_id);
    }
}
