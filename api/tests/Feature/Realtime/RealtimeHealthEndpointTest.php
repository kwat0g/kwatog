<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Common\Models\OutboxMessage;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RealtimeHealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_clients_receive_only_the_delivery_health_signal(): void
    {
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('size')->once()->with('high')->andReturn(0);
        $queue->shouldReceive('size')->once()->with('broadcasts')->andReturn(1);
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->once()->with('redis_broadcasts')->andReturn($queue);
        $this->app->instance(QueueFactory::class, $factory);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/realtime/health')
            ->assertOk()
            ->assertJsonPath('data.delivery', 'degraded');

        $this->assertSame(['data' => ['delivery' => 'degraded']], $response->json());
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_delivery_health_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/realtime/health')->assertUnauthorized();
    }

    public function test_supplier_portal_session_can_read_the_non_sensitive_health_signal(): void
    {
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('size')->once()->with('high')->andReturn(0);
        $queue->shouldReceive('size')->once()->with('broadcasts')->andReturn(0);
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->once()->with('redis_broadcasts')->andReturn($queue);
        $this->app->instance(QueueFactory::class, $factory);

        $this->actingAs(SupplierPortalUser::factory()->create(), 'supplier_portal')
            ->getJson('/api/v1/realtime/health')
            ->assertOk()
            ->assertJsonPath('data.delivery', 'healthy');
    }

    public function test_aged_pending_outbox_message_degrades_delivery_even_when_redis_queues_are_empty(): void
    {
        $this->mockQueueSizes(0, 0);
        $createdAt = now()->subSeconds(10);
        DB::table('event_outbox')->insert([
            'id' => (string) Str::uuid(),
            'event_type' => 'App\\Common\\Events\\TestRefresh',
            'payload' => '{}',
            'dedupe_key' => 'realtime-health-pending-'.Str::uuid(),
            'status' => OutboxMessage::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/realtime/health')
            ->assertOk()
            ->assertJsonPath('data.delivery', 'degraded');
    }

    public function test_failed_outbox_message_degrades_delivery_even_when_redis_queues_are_empty(): void
    {
        $this->mockQueueSizes(0, 0);
        DB::table('event_outbox')->insert([
            'id' => (string) Str::uuid(),
            'event_type' => 'App\\Common\\Events\\TestRefresh',
            'payload' => '{}',
            'dedupe_key' => 'realtime-health-failed-'.Str::uuid(),
            'status' => OutboxMessage::STATUS_FAILED,
            'attempts' => 3,
            'available_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/realtime/health')
            ->assertOk()
            ->assertJsonPath('data.delivery', 'degraded');
    }

    private function mockQueueSizes(int $high, int $broadcasts): void
    {
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('size')->once()->with('high')->andReturn($high);
        $queue->shouldReceive('size')->once()->with('broadcasts')->andReturn($broadcasts);
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->once()->with('redis_broadcasts')->andReturn($queue);
        $this->app->instance(QueueFactory::class, $factory);
    }
}
