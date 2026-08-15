<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WoOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P32/P33 — WoOperation output race (P03-01 shape on the shop floor).
 *
 * `recordOutput` accumulates `qty_completed = stale_value + qty` on the passed
 * model's in-memory value with no row lock. Two concurrent output records both
 * read qty_completed = 0 and both write 5 → the second record's output is LOST
 * (final 5 instead of 10). The operation row must be locked and re-read inside
 * the transaction so each record accumulates on the authoritative value.
 */
class WoOperationOutputRaceTest extends TestCase
{
    use RefreshDatabase;

    private WoOperationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(WoOperationService::class);
    }

    private function inProgressOperation(): WoOperation
    {
        $workOrder = WorkOrder::factory()->create();

        return WoOperation::create([
            'work_order_id'   => $workOrder->id,
            'sequence'        => 1,
            'operation_name'  => 'Injection',
            'status'          => WoOperationStatus::InProgress->value,
            'qty_planned'     => '100.0000',
            'qty_completed'   => '0.0000',
            'qty_scrapped'    => '0.0000',
            'downtime_minutes'=> 0,
        ]);
    }

    public function test_second_concurrent_output_record_accumulates_on_authoritative_value(): void
    {
        $op = $this->inProgressOperation();

        // Two production operators each hold a stale instance of the operation.
        $operatorA = WoOperation::query()->findOrFail($op->id);
        $operatorB = WoOperation::query()->findOrFail($op->id);

        $this->svc->recordOutput($operatorA, 5, 0);
        $this->svc->recordOutput($operatorB, 5, 0);

        // Both records must be counted — no lost output.
        $this->assertSame('10.0000', (string) WoOperation::query()->findOrFail($op->id)->qty_completed);
    }

    public function test_second_concurrent_complete_is_blocked_for_stale_instance(): void
    {
        $op = $this->inProgressOperation();

        // Two operators each hold a stale instance of the in-progress operation.
        $operatorA = WoOperation::query()->findOrFail($op->id);
        $operatorB = WoOperation::query()->findOrFail($op->id);

        $this->svc->completeOperation($operatorA);
        $this->assertSame(
            WoOperationStatus::Completed,
            WoOperation::query()->findOrFail($op->id)->status
        );

        // The second operator acts on the stale in_progress instance — must be
        // blocked (assertStatus on the locked re-read throws), not silently
        // double-completed.
        $this->expectException(\RuntimeException::class);

        $this->svc->completeOperation($operatorB);
    }
}
