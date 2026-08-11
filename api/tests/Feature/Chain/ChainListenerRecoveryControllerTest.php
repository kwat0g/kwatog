<?php

declare(strict_types=1);

namespace Tests\Feature\Chain;

use App\Common\Jobs\ReplayChainListenerJob;
use App\Common\Models\AuditLog;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Listeners\ConsolidatePurchaseOrders;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChainListenerRecoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_is_required_for_listener_runs(): void
    {
        $this->getJson('/api/v1/chain/listener-runs')->assertUnauthorized();
    }

    public function test_listener_run_read_requires_the_recovery_view_permission(): void
    {
        $user = $this->userWithPermissions([]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/chain/listener-runs')
            ->assertForbidden();
    }

    public function test_attention_list_returns_outbox_job_and_chain_correlation_without_payload(): void
    {
        $user = $this->userWithPermissions(['dashboard.chain_recovery.view']);
        $ids = $this->insertRun(
            status: 'failed',
            outcome: 'failed',
            listenerClass: ConsolidatePurchaseOrders::class,
        );
        $this->insertRun(
            status: 'completed',
            outcome: 'completed',
            listenerClass: ConsolidatePurchaseOrders::class,
        );

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/chain/listener-runs');

        $response
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.items.0.id', $ids['run_id'])
            ->assertJsonPath('data.items.0.correlation.outbox_id', $ids['outbox_id'])
            ->assertJsonPath('data.items.0.correlation.job_uuid', $ids['job_uuid'])
            ->assertJsonPath('data.items.0.chain_step.entity_hash_id', 'safe-entity-hash')
            ->assertJsonPath('data.items.0.outbox.status', 'published')
            ->assertJsonPath('data.items.0.resolution.status', 'open');

        $this->assertArrayNotHasKey('payload', $response->json('data.items.0.outbox'));
    }

    public function test_replay_requires_the_manage_permission(): void
    {
        $user = $this->userWithPermissions(['dashboard.chain_recovery.view']);
        $ids = $this->insertRun(
            status: 'failed',
            outcome: 'failed',
            listenerClass: ConsolidatePurchaseOrders::class,
        );

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chain/listener-runs/{$ids['run_id']}/replay")
            ->assertForbidden();
    }

    public function test_manual_resolution_is_audited_and_idempotent(): void
    {
        $user = $this->userWithPermissions(['dashboard.chain_recovery.manage']);
        $ids = $this->insertRun(
            status: 'completed',
            outcome: 'manual_required',
            listenerClass: ConsolidatePurchaseOrders::class,
        );

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chain/listener-runs/{$ids['run_id']}/resolve", [
                'note' => 'Assigned purchasing owner and completed the manual conversion.',
            ]);

        $first
            ->assertOk()
            ->assertJsonPath('data.resolution_status', 'resolved')
            ->assertJsonPath('data.resolved_by', $user->name)
            ->assertJsonPath('data.idempotent', false);

        $this->assertDatabaseHas('chain_listener_runs', [
            'id' => $ids['run_id'],
            'resolution_status' => 'resolved',
            'resolved_by' => $user->id,
            'resolution_note' => 'Assigned purchasing owner and completed the manual conversion.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'chain_listener.resolved',
            'user_id' => $user->id,
        ]);

        $auditCount = AuditLog::query()->where('action', 'chain_listener.resolved')->count();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chain/listener-runs/{$ids['run_id']}/resolve", [
                'note' => 'A second identical click must not overwrite the first note.',
            ])
            ->assertOk()
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.resolution_note', 'Assigned purchasing owner and completed the manual conversion.');

        $this->assertSame($auditCount, AuditLog::query()->where('action', 'chain_listener.resolved')->count());
    }

    public function test_replay_targets_only_the_selected_listener_and_records_lineage(): void
    {
        Queue::fake();
        $user = $this->userWithPermissions(['dashboard.chain_recovery.manage']);
        $pr = PurchaseRequest::factory()->create(['department_id' => null]);
        $encoded = app(OutboxEventCodec::class)->encode(new PurchaseRequestApproved($pr));
        $ids = $this->insertRun(
            status: 'failed',
            outcome: 'failed',
            listenerClass: ConsolidatePurchaseOrders::class,
            eventType: $encoded['event_type'],
            payload: $encoded['payload'],
        );

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chain/listener-runs/{$ids['run_id']}/replay");

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.source_run_id', $ids['run_id'])
            ->assertJsonPath('data.listener_class', ConsolidatePurchaseOrders::class)
            ->assertJsonPath('data.replay_count', 1);

        Queue::assertPushed(
            ReplayChainListenerJob::class,
            fn (ReplayChainListenerJob $job): bool =>
                $job->listenerClass === ConsolidatePurchaseOrders::class
                && $job->listenerMethod === 'handle'
                && $job->event instanceof PurchaseRequestApproved,
        );
        Queue::assertPushed(ReplayChainListenerJob::class, 1);

        $this->assertDatabaseHas('chain_listener_runs', [
            'id' => $ids['run_id'],
            'replay_count' => 1,
            'replay_requested_by' => $user->id,
            'replayed_from_id' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'chain_listener.replay_requested',
            'user_id' => $user->id,
        ]);
    }

    public function test_sync_replay_creates_a_new_listener_run_with_source_lineage(): void
    {
        $user = $this->userWithPermissions(['dashboard.chain_recovery.manage']);
        $pr = PurchaseRequest::factory()->create(['department_id' => null]);
        $pr->forceFill(['status' => 'approved'])->save();
        $encoded = app(OutboxEventCodec::class)->encode(new PurchaseRequestApproved($pr));
        $ids = $this->insertRun(
            status: 'failed',
            outcome: 'failed',
            listenerClass: ConsolidatePurchaseOrders::class,
            eventType: $encoded['event_type'],
            payload: $encoded['payload'],
        );

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chain/listener-runs/{$ids['run_id']}/replay")
            ->assertStatus(202);

        $replay = DB::table('chain_listener_runs')
            ->where('replayed_from_id', $ids['run_id'])
            ->first();

        $this->assertNotNull($replay);
        $this->assertSame(ConsolidatePurchaseOrders::class, $replay->listener_class);
        $this->assertSame('completed', $replay->status);
        $this->assertSame('skipped', $replay->outcome_status);
        $this->assertSame('purchase_request_has_no_lines', $replay->outcome_code);
        $this->assertSame($ids['outbox_id'], $replay->outbox_id);
    }

    public function test_completed_non_manual_run_cannot_be_replayed(): void
    {
        Queue::fake();
        $user = $this->userWithPermissions(['dashboard.chain_recovery.manage']);
        $ids = $this->insertRun(
            status: 'completed',
            outcome: 'completed',
            listenerClass: ConsolidatePurchaseOrders::class,
        );

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/chain/listener-runs/{$ids['run_id']}/replay")
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{run_id: string, outbox_id: string, job_uuid: string}
     */
    private function insertRun(
        string $status,
        string $outcome,
        string $listenerClass,
        string $eventType = 'App\\Modules\\Purchasing\\Events\\PurchaseRequestApproved',
        array $payload = ['purchaseRequest' => ['__type' => 'model', 'class' => 'App\\MissingModel', 'id' => '1']],
    ): array {
        $outboxId = (string) Str::uuid();
        $runId = (string) Str::uuid();
        $jobUuid = (string) Str::uuid();
        $at = now()->subMinutes(30);

        DB::table('event_outbox')->insert([
            'id' => $outboxId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'dedupe_key' => 'recovery-test-'.$runId,
            'status' => 'published',
            'attempts' => 1,
            'available_at' => $at,
            'published_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        DB::table('chain_step_runs')->insert([
            'id' => (string) Str::uuid(),
            'outbox_id' => $outboxId,
            'chain' => 'p2p',
            'entity_type' => 'purchase_request',
            'entity_id' => 1,
            'entity_hash_id' => 'safe-entity-hash',
            'step' => 'approved',
            'event_type' => $eventType,
            'event_key' => 'recovery-test-step-'.$runId,
            'status' => 'published',
            'attempts' => 1,
            'completed_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        DB::table('chain_listener_runs')->insert([
            'id' => $runId,
            'outbox_id' => $outboxId,
            'job_uuid' => $jobUuid,
            'event_type' => $eventType,
            'listener_class' => $listenerClass,
            'listener_method' => 'handle',
            'status' => $status,
            'attempts' => 3,
            'started_at' => $at,
            'last_attempt_at' => $at,
            'completed_at' => $status === 'completed' ? $at : null,
            'failed_at' => $status === 'failed' ? $at : null,
            'last_error' => $status === 'failed' ? 'test listener failure' : null,
            'outcome_status' => $outcome,
            'outcome_code' => $outcome === 'manual_required' ? 'operator_handoff' : 'queue_'.$outcome,
            'outcome_message' => $outcome === 'manual_required' ? 'Manual handoff required.' : null,
            'outcome_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return ['run_id' => $runId, 'outbox_id' => $outboxId, 'job_uuid' => $jobUuid];
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Chain Recovery Test',
            'slug' => 'chain_recovery_test_'.bin2hex(random_bytes(3)),
            'is_system' => false,
        ]);

        foreach ($permissions as $slug) {
            $permission = Permission::query()->create([
                'name' => $slug,
                'slug' => $slug,
                'module' => 'test',
            ]);
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
