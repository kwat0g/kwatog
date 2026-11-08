<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WoOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class WoOperationRealtimeTest extends TestCase
{
    use HasPassedInProcessQc;
    use RefreshDatabase;

    public function test_operation_transition_stages_one_work_order_refresh_ping(): void
    {
        $workOrder = WorkOrder::factory()->create([
            'status' => WorkOrderStatus::InProgress->value,
        ]);
        $this->passInProcessQc($workOrder);
        $operation = WoOperation::query()->create([
            'work_order_id' => $workOrder->id,
            'sequence' => 1,
            'operation_name' => 'Injection',
            'status' => WoOperationStatus::InProgress->value,
            'qty_planned' => '100.0000',
            'qty_completed' => '0.0000',
            'qty_scrapped' => '0.0000',
            'downtime_minutes' => 0,
        ]);

        app(WoOperationService::class)->pauseOperation($operation);

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Production\\Events\\WorkOrderOperationChanged')
            ->get();

        $this->assertCount(1, $messages, 'An operation state change must stage one aggregate refresh event.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($workOrder->hash_id, $payload['workOrderHashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);
    }
}
