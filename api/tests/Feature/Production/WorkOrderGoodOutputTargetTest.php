<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O2C audit 2026-09-25 — a work order's target is the GOOD quantity the order
 * needs, and a WO that produced nothing good cannot be marked completed.
 *
 * Before: rejects consumed the target (a 150-piece WO with 3 rejects could
 * deliver only 147, forcing a 3-piece tail WO), and a WO completed with zero
 * output left the order line short with nothing for outgoing QC to inspect.
 */
class WorkOrderGoodOutputTargetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DefectType $defectType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->defectType = DefectType::create([
            'code' => 'DT-TGT',
            'name' => 'Target test defect',
            'description' => null,
            'is_active' => true,
        ]);
    }

    private function makeWo(int $target, array $overrides = []): WorkOrder
    {
        $product = Product::create([
            'part_number' => 'FG-TGT-'.substr(uniqid(), -5),
            'name' => 'Target FG',
            'unit_of_measure' => 'pcs',
            'standard_cost' => 10.00,
            'is_active' => true,
        ]);

        return WorkOrder::create(array_merge([
            'wo_number' => 'WO-TGT-'.substr(uniqid(), -5),
            'product_id' => $product->id,
            'status' => WorkOrderStatus::InProgress->value,
            'quantity_target' => $target,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => now(),
            'planned_end' => now()->addDay(),
            'actual_start' => now()->subHour(),
            'machine_id' => null,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    public function test_rejects_do_not_use_up_the_good_target(): void
    {
        $wo = $this->makeWo(150);

        app(WorkOrderOutputService::class)->record($wo, [
            'good_count' => 150,
            'reject_count' => 3,
            'defects' => [['defect_type_id' => $this->defectType->id, 'count' => 3]],
        ], $this->user->id);

        $fresh = $wo->fresh();
        $this->assertSame(150, (int) $fresh->quantity_good);
        $this->assertSame(3, (int) $fresh->quantity_rejected);
        $this->assertSame(153, (int) $fresh->quantity_produced);
    }

    public function test_good_output_beyond_the_target_is_refused(): void
    {
        $wo = $this->makeWo(150);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('target of 150 good pieces');

        app(WorkOrderOutputService::class)->record($wo, ['good_count' => 151, 'reject_count' => 0], $this->user->id);
    }

    public function test_a_work_order_without_good_output_cannot_be_completed(): void
    {
        $wo = $this->makeWo(150);

        try {
            app(WorkOrderService::class)->complete($wo);
            $this->fail('Completing a WO with no good output must be refused.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('no good output to complete', $e->getMessage());
        }

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);
    }

    public function test_a_work_order_with_good_output_completes(): void
    {
        $wo = $this->makeWo(10);
        app(WorkOrderOutputService::class)->record($wo, ['good_count' => 10, 'reject_count' => 0], $this->user->id);

        app(WorkOrderService::class)->complete($wo->fresh());

        $this->assertSame(WorkOrderStatus::Completed, $wo->fresh()->status);
    }
}
