<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SalaryAdjustmentChanged;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Services\SalaryAdjustmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalaryAdjustmentApprovalRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, WorkflowSeeder::class]);
    }

    public function test_intermediate_approval_refreshes_salary_adjustment_after_commit(): void
    {
        $employee = Employee::factory()->create(['basic_monthly_salary' => '30000.00']);
        $requester = $this->user('hr_officer');
        $checker = $this->user('production_manager');

        $service = app(SalaryAdjustmentService::class);
        $adjustment = $service->request($employee, [
            'to_basic_monthly_salary' => '35000.00',
            'effective_date' => '2026-09-01',
            'reason' => 'Promotion',
        ], $requester);

        $createdMessage = OutboxMessage::query()
            ->where('event_type', SalaryAdjustmentChanged::class)
            ->sole();
        $createdEvent = app(OutboxEventCodec::class)->decode($createdMessage->event_type, $createdMessage->payload);
        $this->assertSame('created', $createdEvent->broadcastWith()['action']);

        $this->actingAs($checker)
            ->getJson('/api/v1/hr/salary-adjustments?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.available_actions', ['approve', 'reject'])
            ->assertJsonPath('data.0.permissions.act', true);

        DB::table('event_outbox')->delete();
        $service->approve(SalaryAdjustment::query()->findOrFail($adjustment->id), $checker);

        $this->assertSame(
            1,
            OutboxMessage::query()->where('event_type', SalaryAdjustmentChanged::class)->count(),
            'Advancing an approval step must refresh viewers even when the parent status remains pending.',
        );

        $message = OutboxMessage::query()
            ->where('event_type', SalaryAdjustmentChanged::class)
            ->sole();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertContainsOnlyInstancesOf(PrivateChannel::class, $event->broadcastOn());
        $this->assertSame('salary_adjustment', $event->broadcastWith()['entity_type']);
        $this->assertSame($adjustment->hash_id, $event->broadcastWith()['hash_id']);
        $payload = $event->broadcastWith();
        $this->assertSame('status_changed', $payload['action']);
        $this->assertSame($checker->hash_id, $payload['actor_id']);
        $this->assertArrayNotHasKey('to_basic_monthly_salary', $payload);
        $this->assertTrue(
            Carbon::parse($payload['version'])->greaterThan($adjustment->updated_at),
            sprintf('Expected event version %s to be newer than loaded version %s.', $payload['version'], $adjustment->updated_at?->format('Y-m-d\\TH:i:s.uP')),
        );
        $this->assertSame(
            'pending',
            SalaryAdjustment::query()->findOrFail($adjustment->id)->status->value,
            'This verifies the intermediate step, before the parent status transitions.',
        );

        $this->actingAs($checker)
            ->getJson('/api/v1/hr/salary-adjustments?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.available_actions', [])
            ->assertJsonPath('data.0.permissions.act', true);

        $finalApprover = $this->user('vice_president');
        $this->actingAs($finalApprover)
            ->getJson('/api/v1/hr/salary-adjustments?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.available_actions', ['approve', 'reject'])
            ->assertJsonPath('data.0.permissions.act', true);
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);
    }
}
