<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Common\Models\ActivityEvent;
use App\Common\Models\AuditLog;
use App\Common\Services\ActivityFeedService;
use App\Modules\Admin\Listeners\RecordActivityFromEvent;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_activity_record_is_idempotent_under_replay(): void
    {
        $service = app(ActivityFeedService::class);

        $first = $service->record(
            type: 'automation',
            action: 'test_run',
            summary: 'Test run',
            idempotencyKey: 'audit-activity-test-key',
        );
        $second = $service->record(
            type: 'automation',
            action: 'test_run',
            summary: 'Test run',
            idempotencyKey: 'audit-activity-test-key',
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, ActivityEvent::where('idempotency_key', 'audit-activity-test-key')->count());
    }

    public function test_date_only_to_filter_includes_the_selected_day(): void
    {
        $service = app(ActivityFeedService::class);

        $service->record(
            type: 'transaction',
            action: 'same_day',
            summary: 'Same day',
            createdAt: now()->setDate(2026, 8, 10)->setTime(23, 59, 59),
        );
        $service->record(
            type: 'transaction',
            action: 'next_day',
            summary: 'Next day',
            createdAt: now()->setDate(2026, 8, 11)->startOfDay(),
        );

        $page = $service->feed(['to' => '2026-08-10', 'per_page' => 100]);

        $this->assertSame(1, $page->total());
        $this->assertSame('same_day', $page->first()->action);
    }

    public function test_domain_event_projection_is_idempotent_and_classified(): void
    {
        $order = PurchaseOrder::factory()->create();
        $listener = app(RecordActivityFromEvent::class);
        $event = new PurchaseOrderApproved($order);

        $listener->handle($event);
        $listener->handle($event);

        $activity = ActivityEvent::query()->where('action', 'purchase_order_approved')->first();

        $this->assertNotNull($activity);
        $this->assertSame('approval', $activity->type);
        $this->assertSame('success', $activity->severity);
        $this->assertSame(1, ActivityEvent::where('action', 'purchase_order_approved')->count());
        $this->assertSame((int) $order->getKey(), (int) $activity->subject_id);
    }

    public function test_auth_audit_rows_are_projected_to_the_activity_feed(): void
    {
        $admin = $this->seedAdmin();

        $log = AuditLog::create([
            'user_id' => $admin->id,
            'action' => 'login.failed',
            'model_type' => 'auth.event',
            'model_id' => $admin->id,
            'new_values' => ['email' => $admin->email],
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $activity = ActivityEvent::query()->where('idempotency_key', hash('sha256', 'audit-log:'.$log->getKey()))->first();

        $this->assertNotNull($activity);
        $this->assertSame('auth', $activity->type);
        $this->assertSame('danger', $activity->severity);
        $this->assertSame('/admin/audit-logs/'.$log->hash_id, $activity->link);
    }

    public function test_audit_api_exposes_context_and_hashes_model_id(): void
    {
        $admin = $this->seedAdmin();
        $log = AuditLog::create([
            'user_id' => $admin->id,
            'actor_type' => 'user',
            'source_command' => 'admin.role.update',
            'correlation_id' => 'request-123',
            'reason' => 'Reviewed by security',
            'action' => 'updated',
            'model_type' => 'App\\Modules\\Inventory\\Models\\Item',
            'model_id' => 42,
            'old_values' => ['name' => 'Old'],
            'new_values' => ['name' => 'New'],
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-logs/'.$log->hash_id)
            ->assertOk()
            ->assertJsonPath('data.model_id', app('hashids')->encode(42))
            ->assertJsonPath('data.actor_type', 'user')
            ->assertJsonPath('data.source_command', 'admin.role.update')
            ->assertJsonPath('data.correlation_id', 'request-123')
            ->assertJsonPath('data.reason', 'Reviewed by security');

        $csv = $this->actingAs($admin)->get('/api/v1/admin/audit-logs/export');
        $csv->assertOk();
        $this->assertStringContainsString(app('hashids')->encode(42), $csv->streamedContent());
    }

    public function test_entity_trail_is_paginated_and_uses_shared_diff_metadata(): void
    {
        $admin = $this->seedAdmin();
        foreach (range(1, 30) as $index) {
            AuditLog::create([
                'user_id' => $admin->id,
                'action' => 'updated',
                'model_type' => 'App\\Modules\\Purchasing\\Models\\PurchaseOrder',
                'model_id' => 42,
                'old_values' => ['total_amount' => '10.00'],
                'new_values' => ['total_amount' => (string) (10 + $index).'.00'],
                'created_at' => now()->subMinutes($index),
            ]);
        }

        // model_id is a HashID on this endpoint (see AuditLogResource and the
        // SPA client), not an integer. See AuditLogSearchTest for why the raw
        // integer form only worked in CI.
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/audit-logs/entity?model_type=PurchaseOrder&model_id='.app('hashids')->encode(42).'&per_page=25')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.diff.0.label', 'Total')
            ->assertJsonPath('data.0.diff.0.type', 'money');
    }

    public function test_activity_endpoint_rejects_unknown_type(): void
    {
        $this->actingAs($this->seedAdmin())
            ->getJson('/api/v1/admin/activity?type=not-a-real-type')
            ->assertStatus(422);
    }

    public function test_activity_events_are_immutable_at_the_model_boundary(): void
    {
        $event = ActivityEvent::create([
            'type' => 'transaction',
            'action' => 'immutable_test',
            'summary' => 'Immutable test',
            'created_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $event->update(['summary' => 'changed']);
    }

    private function seedAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'email' => 'activity-admin-'.uniqid().'@test.local',
        ]);
    }
}
