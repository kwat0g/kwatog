<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Models\ChainEvent;
use App\Common\Services\ChainEventRecorder;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChainEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name'     => 'T',
            'email'    => 'u_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    public function test_record_writes_an_audit_row_and_captures_actor(): void
    {
        $actor = $this->makeUser();
        $entity = Department::create(['name' => 'Test', 'code' => 'TST_'.uniqid()]);

        $event = ChainEventRecorder::record(
            chainKey:   'leave_request',
            entity:     $entity,
            eventType:  'submitted',
            fromState:  null,
            toState:    'pending_dept',
            actor:      $actor,
            metadata:   ['source' => 'unit-test'],
        );

        $this->assertNotNull($event);
        $this->assertSame('leave_request',  $event->chain_key);
        $this->assertSame('submitted',      $event->event_type);
        $this->assertSame('pending_dept',   $event->to_state);
        $this->assertSame($actor->id,       $event->actor_id);
        $this->assertSame(['source' => 'unit-test'], $event->metadata);
    }

    public function test_recording_with_same_idempotency_key_creates_only_one_row(): void
    {
        $actor  = $this->makeUser();
        $entity = Department::create(['name' => 'Test', 'code' => 'TST2_'.uniqid()]);
        $key    = 'dept:'.$entity->id.':seeded';

        $first  = ChainEventRecorder::record(
            chainKey: 'leave_request', entity: $entity, eventType: 'seeded',
            actor: $actor, idempotencyKey: $key,
        );
        $second = ChainEventRecorder::record(
            chainKey: 'leave_request', entity: $entity, eventType: 'seeded',
            actor: $actor, idempotencyKey: $key,
        );

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, ChainEvent::where('idempotency_key', $key)->count());
    }

    public function test_recording_without_idempotency_key_allows_duplicates(): void
    {
        $actor  = $this->makeUser();
        $entity = Department::create(['name' => 'Test', 'code' => 'TST3_'.uniqid()]);

        ChainEventRecorder::record('leave_request', $entity, 'noted', actor: $actor);
        ChainEventRecorder::record('leave_request', $entity, 'noted', actor: $actor);

        $this->assertSame(
            2,
            ChainEvent::where('entity_id', $entity->id)
                ->where('event_type', 'noted')
                ->count(),
        );
    }
}
