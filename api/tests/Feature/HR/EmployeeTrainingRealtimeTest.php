<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\HR\Events\EmployeeTrainingChanged;
use App\Modules\HR\Events\TrainingCatalogChanged;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Services\EmployeeTrainingService;
use App\Modules\HR\Services\TrainingService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeTrainingRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_training_assignment_coalesces_create_and_status_writes(): void
    {
        $employee = Employee::factory()->create();
        $training = Training::query()->create(['name' => 'Forklift Safety', 'is_active' => true]);

        $record = app(EmployeeTrainingService::class)->assign($employee, $training);

        $this->assertSame(
            1,
            OutboxMessage::query()->where('event_type', EmployeeTrainingChanged::class)->count(),
        );
        $this->assertEmployeeTrainingSignal($record, 'created');
    }

    public function test_employee_training_updates_emit_a_refresh_for_authorized_record_and_list_viewers(): void
    {
        $employee = Employee::factory()->create();
        $training = Training::query()->create(['name' => 'Forklift Safety', 'is_active' => true]);
        $record = EmployeeTraining::query()->create([
            'employee_id' => $employee->id,
            'training_id' => $training->id,
            'scheduled_for' => '2026-10-01',
        ]);
        DB::table('event_outbox')->delete();

        $record->forceFill(['notes' => 'Updated notes'])->save();

        $this->assertEmployeeTrainingSignal($record, 'updated');
    }

    public function test_training_catalog_create_update_archive_and_restore_emit_refresh_signals(): void
    {
        $service = app(TrainingService::class);
        $training = $service->create(['name' => 'Forklift Safety', 'is_active' => true]);
        $this->assertTrainingSignal($training, 'created');

        DB::table('event_outbox')->delete();
        $training = $service->update($training, ['name' => 'Forklift Safety v2']);
        $this->assertTrainingSignal($training, 'updated');

        DB::table('event_outbox')->delete();
        $service->delete($training);
        $this->assertTrainingSignal($training, 'deleted');

        DB::table('event_outbox')->delete();
        $restored = Training::withTrashed()->findOrFail($training->id);
        $restored->restore();
        $this->assertTrainingSignal($restored, 'restored');
    }

    public function test_training_update_rejects_a_stale_if_match_version(): void
    {
        $service = app(TrainingService::class);
        $training = $service->create(['name' => 'Forklift Safety', 'is_active' => true]);
        $staleVersion = '"'.$training->updated_at?->format('Y-m-d\\TH:i:s.uP').'"';

        $service->update($training, ['name' => 'Updated elsewhere']);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('This training changed after you loaded it.');
        $service->update($training, ['name' => 'Stale overwrite'], $staleVersion);
    }

    private function assertEmployeeTrainingSignal(EmployeeTraining $record, string $action): void
    {
        $message = OutboxMessage::query()
            ->where('event_type', EmployeeTrainingChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);
        $payload = $event->broadcastWith();
        $channels = $event->broadcastOn();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame([
            'private-entity.employee_training.visible',
            "private-employee_training.{$record->hash_id}",
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $channels));
        $this->assertSame('employee_training', $payload['entity_type']);
        $this->assertSame($action, $payload['action']);
        $this->assertSame([
            'refresh', 'event_id', 'entity_type', 'action', 'version', 'timestamp',
        ], array_keys($payload));
        $this->assertArrayNotHasKey('hash_id', $payload);
        $this->assertArrayNotHasKey('notes', $payload);
        $this->assertArrayNotHasKey('certificate', $payload);
    }

    private function assertTrainingSignal(Training $training, string $action): void
    {
        $message = OutboxMessage::query()
            ->where('event_type', TrainingCatalogChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame([
            'private-entity.training.visible',
            "private-training.{$training->hash_id}",
        ], array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn()));
        $this->assertSame('training', $payload['entity_type']);
        $this->assertSame($action, $payload['action']);
        $this->assertSame([
            'refresh', 'event_id', 'entity_type', 'action', 'version', 'timestamp',
        ], array_keys($payload));
        $this->assertArrayNotHasKey('name', $payload);
        $this->assertArrayNotHasKey('description', $payload);
    }
}
