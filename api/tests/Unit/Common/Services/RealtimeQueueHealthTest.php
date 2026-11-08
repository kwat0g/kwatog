<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Services;

use App\Common\Services\RealtimeQueueHealth;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class RealtimeQueueHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_is_healthy_only_when_outbox_and_broadcast_queues_are_empty(): void
    {
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('size')->once()->with('high')->andReturn(0);
        $queue->shouldReceive('size')->once()->with('broadcasts')->andReturn(0);
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->once()->with('redis_broadcasts')->andReturn($queue);

        $this->assertTrue((new RealtimeQueueHealth($factory))->deliveryHealthy());
    }

    public function test_a_pending_broadcast_marks_delivery_degraded(): void
    {
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('size')->once()->with('high')->andReturn(0);
        $queue->shouldReceive('size')->once()->with('broadcasts')->andReturn(1);
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->once()->with('redis_broadcasts')->andReturn($queue);

        $this->assertFalse((new RealtimeQueueHealth($factory))->deliveryHealthy());
    }

    public function test_a_pending_outbox_dispatch_marks_delivery_degraded(): void
    {
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('size')->once()->with('high')->andReturn(1);
        $queue->shouldReceive('size')->once()->with('broadcasts')->andReturn(0);
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->once()->with('redis_broadcasts')->andReturn($queue);

        $this->assertFalse((new RealtimeQueueHealth($factory))->deliveryHealthy());
    }

    public function test_a_stale_processing_outbox_message_marks_delivery_degraded(): void
    {
        Cache::forget('realtime:delivery-health:v3');
        DB::table('event_outbox')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => 'test.refresh',
            'payload' => json_encode([]),
            'dedupe_key' => 'test:stale-processing',
            'status' => 'processing',
            'attempts' => 1,
            'available_at' => now()->subSeconds(5),
            'locked_at' => now()->subSeconds(5),
            'created_at' => now()->subSeconds(5),
            'updated_at' => now()->subSeconds(5),
        ]);

        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('size')->once()->with('high')->andReturn(0);
        $queue->shouldReceive('size')->once()->with('broadcasts')->andReturn(0);
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->once()->with('redis_broadcasts')->andReturn($queue);

        $this->assertFalse((new RealtimeQueueHealth($factory))->deliveryHealthy());
    }

    public function test_queue_health_fails_closed_when_redis_cannot_be_checked(): void
    {
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')
            ->once()
            ->with('redis_broadcasts')
            ->andThrow(new \RuntimeException('Queue unavailable'));

        $this->assertFalse((new RealtimeQueueHealth($factory))->deliveryHealthy());
    }
}
