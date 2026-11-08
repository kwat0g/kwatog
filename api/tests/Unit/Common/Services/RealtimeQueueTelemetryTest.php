<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Services;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Services\RealtimeQueueTelemetry;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RealtimeQueueTelemetryTest extends TestCase
{
    public function test_realtime_queue_wait_and_execution_are_logged_without_payload_contents(): void
    {
        $channel = Mockery::mock();
        Log::shouldReceive('channel')->with('realtime')->andReturn($channel);
        $channel->shouldReceive('info')->twice()->withArgs(static function (string $message, array $context): bool {
            if ($message === 'realtime.queue.started') {
                return $context['job'] === DispatchOutboxMessage::class
                    && $context['job_id'] === 'job-123'
                    && $context['queue'] === 'high'
                    && $context['queue_wait_ms'] >= 200
                    && ! array_key_exists('payload', $context);
            }

            if ($message === 'realtime.queue.completed') {
                return $context['job'] === DispatchOutboxMessage::class
                    && $context['job_id'] === 'job-123'
                    && $context['duration_ms'] >= 0
                    && ! array_key_exists('payload', $context);
            }

            return false;
        });
        $payload = ['realtime_queue_enqueued_at' => microtime(true) - 0.25];
        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('resolveName')->twice()->andReturn(DispatchOutboxMessage::class);
        $job->shouldReceive('getJobId')->twice()->andReturn('job-123');
        $job->shouldReceive('getQueue')->twice()->andReturn('high');
        $job->shouldReceive('payload')->twice()->andReturn($payload);

        $telemetry = new RealtimeQueueTelemetry;
        $telemetry->started(new JobProcessing('redis', $job));
        $telemetry->processed(new JobProcessed('redis', $job));
    }

    public function test_broadcast_wrapper_is_measured_by_its_command_class_and_standard_enqueue_time(): void
    {
        $channel = Mockery::mock();
        Log::shouldReceive('channel')->with('realtime')->andReturn($channel);
        $channel->shouldReceive('info')->twice()->withArgs(static function (string $message, array $context): bool {
            if ($message === 'realtime.queue.started') {
                return $context['job'] === \Illuminate\Broadcasting\BroadcastEvent::class
                    && $context['queue'] === 'broadcasts'
                    && $context['queue_wait_source'] === 'created_at_seconds'
                    && $context['queue_wait_ms'] >= 0;
            }

            return $message === 'realtime.queue.completed'
                && $context['job'] === \Illuminate\Broadcasting\BroadcastEvent::class
                && $context['duration_ms'] >= 0;
        });

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('resolveName')->never();
        $job->shouldReceive('getJobId')->twice()->andReturn('broadcast-job-123');
        $job->shouldReceive('getQueue')->twice()->andReturn('broadcasts');
        $job->shouldReceive('payload')->twice()->andReturn([
            'displayName' => \App\Common\Events\ChainStepAdvanced::class,
            'createdAt' => time() - 1,
            'data' => ['commandName' => \Illuminate\Broadcasting\BroadcastEvent::class],
        ]);

        $telemetry = new RealtimeQueueTelemetry;
        $telemetry->started(new JobProcessing('redis', $job));
        $telemetry->processed(new JobProcessed('redis', $job));
    }

    public function test_queue_dispatch_log_records_only_safe_metadata_for_broadcast_jobs(): void
    {
        $channel = Mockery::mock();
        Log::shouldReceive('channel')->with('realtime')->andReturn($channel);
        $channel->shouldReceive('info')->once()->withArgs(static fn (string $message, array $context): bool =>
            $message === 'realtime.queue.dispatched'
            && $context['job'] === \Illuminate\Broadcasting\BroadcastEvent::class
            && $context['job_id'] === 'broadcast-job-456'
            && $context['queue'] === 'broadcasts'
            && $context['precise_enqueue_timestamp_present'] === true
            && $context['broadcast_event'] === \App\Common\Events\ChainStepAdvanced::class
            && ! array_key_exists('payload', $context)
        );

        $event = new JobQueued(
            'redis',
            'broadcasts',
            'broadcast-job-456',
            new \stdClass,
            json_encode([
                'displayName' => \App\Common\Events\ChainStepAdvanced::class,
                'data' => ['commandName' => \Illuminate\Broadcasting\BroadcastEvent::class],
                'realtime_queue_enqueued_at' => microtime(true),
            ], JSON_THROW_ON_ERROR),
            null,
        );

        (new RealtimeQueueTelemetry)->queued($event);
    }

    public function test_only_realtime_queues_receive_the_precise_enqueue_timestamp(): void
    {
        $queue = Queue::connection('redis');
        $createPayload = new \ReflectionMethod($queue, 'createPayload');
        $createPayload->setAccessible(true);
        $job = new DispatchOutboxMessage('telemetry-test-only');

        foreach (['queues:high', 'queues:broadcasts'] as $redisQueueKey) {
            $payload = json_decode($createPayload->invoke($queue, $job, $redisQueueKey), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsNumeric($payload['realtime_queue_enqueued_at'] ?? null, $redisQueueKey);
        }

        $defaultPayload = json_decode($createPayload->invoke($queue, $job, 'queues:default'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('realtime_queue_enqueued_at', $defaultPayload);
    }
}
