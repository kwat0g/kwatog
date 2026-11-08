<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Events\ImportBatchChanged;
use App\Common\Models\ImportBatch;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportBatchRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_batch_creation_stages_a_content_free_private_refresh(): void
    {
        $manager = $this->userWithPermissions('admin.import.manage');
        $this->actingAs($manager);

        $batch = DB::transaction(function () use ($manager): ImportBatch {
            $batch = ImportBatch::query()->create([
                'entity_type' => 'items',
                'filename' => 'sensitive-cutover-items.csv',
                'status' => 'committed',
                'total_rows' => 4,
                'imported_rows' => 0,
                'created_by' => $manager->id,
            ]);
            $batch->update(['imported_rows' => 4]);

            return $batch;
        });

        $message = DB::table('event_outbox')
            ->where('event_type', ImportBatchChanged::class)
            ->sole();
        $event = app(OutboxEventCodec::class)->decode(
            $message->event_type,
            json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR),
        );

        self::assertInstanceOf(ImportBatchChanged::class, $event);
        self::assertSame('created', $event->action);
        self::assertSame($batch->hash_id, $event->batchHashId);
        self::assertSame(['private-entity.admin_import_batch.visible'], array_map(
            static fn ($channel) => $channel->name,
            $event->broadcastOn(),
        ));
        self::assertSame([
            'refresh' => true,
            'event_id' => $event->eventId,
            'entity_type' => 'import_batch',
            'entity_id' => $batch->hash_id,
            'action' => 'created',
            'version' => $event->version,
            'actor_id' => $manager->hash_id,
            'timestamp' => $event->timestamp,
        ], $event->broadcastWith());
        self::assertStringNotContainsString('sensitive-cutover-items.csv', json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR));
    }

    public function test_batch_status_change_stages_a_refresh(): void
    {
        $manager = $this->userWithPermissions('admin.import.manage');
        $this->actingAs($manager);
        $batch = ImportBatch::withoutEvents(fn () => ImportBatch::query()->create([
            'entity_type' => 'items',
            'filename' => 'rollback-items.csv',
            'status' => 'committed',
            'total_rows' => 1,
            'imported_rows' => 1,
            'created_by' => $manager->id,
        ]));

        $batch->update(['status' => 'rolled_back', 'rolled_back_at' => now()]);

        $payload = json_decode((string) DB::table('event_outbox')
            ->where('event_type', ImportBatchChanged::class)
            ->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('status_changed', $payload['action']);
        self::assertSame($batch->hash_id, $payload['batchHashId']);
    }

    public function test_only_active_import_managers_can_join_batch_history_channel(): void
    {
        $manager = $this->userWithPermissions('admin.import.manage');
        $unrelated = $this->userWithPermissions('admin.audit_logs.view');

        $this->authorizeChannel($manager)->assertOk();
        $this->authorizeChannel($unrelated)->assertForbidden();

        $manager->update(['is_active' => false]);
        $this->authorizeChannel($manager)->assertForbidden();
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Import refresh '.substr(uniqid(), -6),
            'slug' => 'import_refresh_'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function authorizeChannel(User $user)
    {
        return $this->actingAs($user)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-entity.admin_import_batch.visible',
        ]);
    }
}
