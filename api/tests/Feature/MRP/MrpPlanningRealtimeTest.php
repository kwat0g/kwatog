<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\MrpRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MrpPlanningRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_create_and_update_coalesce_one_refresh_signal_per_plan_transaction(): void
    {
        $salesOrder = SalesOrder::factory()->create();
        $generator = User::factory()->create();

        DB::transaction(function () use ($salesOrder, $generator): void {
            $previous = MrpPlan::query()->create([
                'mrp_plan_no' => 'MRP-REALTIME-OLD',
                'sales_order_id' => $salesOrder->id,
                'version' => 1,
                'status' => 'active',
                'generated_by' => $generator->id,
                'generated_at' => now(),
            ]);
            $previous->update(['status' => 'superseded']);

            MrpPlan::query()->create([
                'mrp_plan_no' => 'MRP-REALTIME-NEW',
                'sales_order_id' => $salesOrder->id,
                'version' => 2,
                'status' => 'active',
                'generated_by' => $generator->id,
                'generated_at' => now(),
            ]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\MrpPlanChanged')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $messages, 'Old and new plan rows each need one coalesced refresh signal.');
        $payloads = $messages->map(fn ($row): array => json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR));
        $this->assertEqualsCanonicalizing(['created', 'status_changed'], $payloads->pluck('action')->all());
        $this->assertTrue($payloads->every(fn (array $payload): bool => ! empty($payload['planHashId']) && ! empty($payload['version'])));
        $this->assertTrue($messages->every(fn ($row): bool => $row->status === 'pending'));
    }

    public function test_run_create_and_completion_coalesce_one_refresh_signal_per_transaction(): void
    {
        DB::transaction(function (): void {
            $run = MrpRun::query()->create([
                'run_at' => now(),
                'started_at' => now(),
                'heartbeat_at' => now(),
                'triggered_by' => 'manual',
                'status' => 'running',
                'summary' => ['sales_order_ids' => []],
            ]);
            $run->update(['status' => 'completed', 'summary' => ['sales_order_ids' => []]]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\MRP\\Events\\MrpRunChanged')
            ->get();

        $this->assertCount(1, $messages, 'Run start and completion in one transaction must coalesce.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('status_changed', $payload['action']);
        $this->assertNotEmpty($payload['runHashId']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);
    }
}
