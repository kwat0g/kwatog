<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private InspectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_active' => true]);
        $this->service = app(InspectionService::class);
    }

    public function test_completion_rechecks_a_terminal_inspection_before_writing(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $stale = $inspection->fresh();

        $inspection->forceFill([
            'status' => InspectionStatus::Passed,
            'completed_at' => now(),
        ])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Inspection is already finalised.');

        $this->service->complete($stale, $this->user);
    }

    public function test_measurement_entry_cannot_reopen_a_terminal_inspection(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $stale = $inspection->fresh();
        $measurement = $inspection->measurements()->firstOrFail();

        $inspection->forceFill([
            'status' => InspectionStatus::Failed,
            'completed_at' => now(),
        ])->save();

        try {
            $this->service->recordMeasurements($stale, [
                $measurement->id => ['is_pass' => true],
            ], $this->user);
            $this->fail('A terminal inspection must reject stale measurement input.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Inspection is already finalised.', $e->getMessage());
        }

        $this->assertSame(InspectionStatus::Failed, $inspection->fresh()->status);
        $this->assertFalse((bool) $measurement->fresh()->is_pass);
    }

    public function test_cancellation_cannot_overwrite_a_terminal_inspection(): void
    {
        $inspection = $this->makeInspection(InspectionStatus::InProgress);
        $stale = $inspection->fresh();

        $inspection->forceFill([
            'status' => InspectionStatus::Passed,
            'completed_at' => now(),
        ])->save();

        try {
            $this->service->cancel($stale, 'stale operator request', $this->user);
            $this->fail('A terminal inspection must reject stale cancellation.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Inspection is already finalised.', $e->getMessage());
        }

        $this->assertSame(InspectionStatus::Passed, $inspection->fresh()->status);
    }

    private function makeInspection(InspectionStatus $status): Inspection
    {
        $product = Product::factory()->create();
        $inspection = Inspection::query()->create([
            'inspection_number' => 'QC-LOCK-'.uniqid(),
            'stage' => InspectionStage::Outgoing,
            'status' => $status,
            'product_id' => $product->id,
            'batch_quantity' => 10,
            'sample_size' => 1,
            'accept_count' => 0,
            'reject_count' => 1,
            'defect_count' => 0,
            'inspector_id' => $this->user->id,
            'started_at' => now(),
        ]);

        InspectionMeasurement::query()->create([
            'inspection_id' => $inspection->id,
            'sample_index' => 1,
            'parameter_name' => 'Visual verdict',
            'parameter_type' => 'visual',
            'is_critical' => true,
            'is_pass' => false,
        ]);

        return $inspection->fresh(['measurements']);
    }
}
