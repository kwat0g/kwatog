<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\EmployeeChanged;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_changes_emit_scoped_content_free_after_commit_refreshes(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $linkedUser = User::factory()->create(['employee_id' => $employee->id]);
        DB::table('event_outbox')->delete();

        $employee->update(['basic_monthly_salary' => '42000.00']);

        $message = OutboxMessage::query()
            ->where('event_type', EmployeeChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);
        $payload = $event->broadcastWith();
        $channels = array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame([
            'private-entity.employee.visible',
            "private-employee.{$employee->hash_id}",
            "private-entity.employee.department.{$department->hash_id}",
            "private-employee.user.{$linkedUser->hash_id}",
        ], $channels);
        $this->assertSame('employee', $payload['entity_type']);
        $this->assertSame($employee->hash_id, $payload['hash_id']);
        $this->assertSame('updated', $payload['action']);
        $this->assertSame([
            'refresh', 'event_id', 'entity_type', 'hash_id', 'action', 'version',
            'actor_id', 'timestamp', 'related',
        ], array_keys($payload));
        $this->assertArrayNotHasKey('basic_monthly_salary', $payload);
        $this->assertArrayNotHasKey('semi_monthly_rate', $payload);
        $this->assertSame([
            ['entity_type' => 'department', 'hash_id' => $department->hash_id],
            ['entity_type' => 'position', 'hash_id' => $employee->position->hash_id],
        ], $payload['related']);
    }
}
