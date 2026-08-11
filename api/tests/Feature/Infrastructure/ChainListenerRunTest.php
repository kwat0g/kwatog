<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Models\ChainListenerRun;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\OutboxService;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Listeners\NotifyOnPurchaseOrderApproved;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ChainListenerRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_queued_listener_is_correlated_to_its_outbox_message(): void
    {
        $po = PurchaseOrder::factory()->create();

        $message = app(OutboxService::class)->recordForChain(
            new PurchaseOrderApproved($po),
            $po,
            'p2p',
            'purchase_order',
            'approved',
        );

        $run = ChainListenerRun::query()
            ->where('outbox_id', $message->getKey())
            ->where('listener_class', NotifyOnPurchaseOrderApproved::class)
            ->first();

        $this->assertNotNull($run);
        $this->assertSame(ChainListenerRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(PurchaseOrderApproved::class, $run->event_type);
        $this->assertSame('handle', $run->listener_method);
        $this->assertGreaterThanOrEqual(1, $run->attempts);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(ChainListenerRun::OUTCOME_COMPLETED, $run->outcome_status);
        $this->assertSame('queue_completed', $run->outcome_code);
    }

    public function test_retry_and_dead_letter_states_are_recorded_for_a_listener_job(): void
    {
        $outboxId = (string) \Illuminate\Support\Str::uuid();
        $jobUuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'outbox_id' => $outboxId,
            'outbox_event_type' => PurchaseOrderApproved::class,
            'uuid' => $jobUuid,
            'displayName' => NotifyOnPurchaseOrderApproved::class,
        ];

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('attempts')->andReturn(2);

        $service = app(ChainListenerRunService::class);
        $service->markProcessing(new JobProcessing('sync', $job));
        $service->markRetrying(new JobExceptionOccurred(
            'sync',
            $job,
            new RuntimeException('temporary listener failure'),
        ));

        $run = ChainListenerRun::query()->where('job_uuid', $jobUuid)->firstOrFail();
        $this->assertSame(ChainListenerRun::STATUS_RETRYING, $run->status);
        $this->assertSame(2, $run->attempts);
        $this->assertStringContainsString('temporary listener failure', (string) $run->last_error);

        $service->markFailed(new JobFailed(
            'sync',
            $job,
            new RuntimeException('permanent listener failure'),
        ));

        $run->refresh();
        $this->assertSame(ChainListenerRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->failed_at);
        $this->assertStringContainsString('permanent listener failure', (string) $run->last_error);
        $this->assertSame(ChainListenerRun::OUTCOME_FAILED, $run->outcome_status);
        $this->assertSame('queue_failed', $run->outcome_code);
    }

    public function test_a_late_exception_event_cannot_reopen_a_terminal_failed_listener_run(): void
    {
        $outboxId = (string) \Illuminate\Support\Str::uuid();
        $jobUuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'outbox_id' => $outboxId,
            'outbox_event_type' => PurchaseOrderApproved::class,
            'uuid' => $jobUuid,
            'displayName' => NotifyOnPurchaseOrderApproved::class,
        ];

        DB::table('failed_jobs')->insert([
            'uuid' => $jobUuid,
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'exception' => 'permanent listener failure',
            'failed_at' => now(),
        ]);

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('attempts')->andReturn(3);

        $service = app(ChainListenerRunService::class);
        $service->markProcessing(new JobProcessing('redis', $job));
        $service->markFailed(new JobFailed(
            'redis',
            $job,
            new RuntimeException('permanent listener failure'),
        ));

        $failed = ChainListenerRun::query()->where('job_uuid', $jobUuid)->firstOrFail();
        $terminal = [
            'status' => $failed->status,
            'attempts' => $failed->attempts,
            'failed_at' => $failed->failed_at?->toIso8601String(),
            'last_error' => $failed->last_error,
            'outcome_status' => $failed->outcome_status,
            'outcome_code' => $failed->outcome_code,
            'outcome_message' => $failed->outcome_message,
            'outcome_at' => $failed->outcome_at?->toIso8601String(),
            'job_uuid' => $failed->job_uuid,
        ];

        // Laravel may dispatch JobFailed before JobExceptionOccurred when a
        // job has exhausted its attempts. The latter is an informational
        // retry event and must not erase the terminal failure fact.
        $service->markRetrying(new JobExceptionOccurred(
            'redis',
            $job,
            new RuntimeException('late retry notification'),
        ));

        $failed->refresh();
        $this->assertSame($terminal, [
            'status' => $failed->status,
            'attempts' => $failed->attempts,
            'failed_at' => $failed->failed_at?->toIso8601String(),
            'last_error' => $failed->last_error,
            'outcome_status' => $failed->outcome_status,
            'outcome_code' => $failed->outcome_code,
            'outcome_message' => $failed->outcome_message,
            'outcome_at' => $failed->outcome_at?->toIso8601String(),
            'job_uuid' => $failed->job_uuid,
        ]);
        $this->assertSame($jobUuid, DB::table('failed_jobs')->where('uuid', $jobUuid)->value('uuid'));
    }

    public function test_terminal_failure_without_a_matching_failed_job_is_safe(): void
    {
        $outboxId = (string) \Illuminate\Support\Str::uuid();
        $jobUuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'outbox_id' => $outboxId,
            'outbox_event_type' => PurchaseOrderApproved::class,
            'uuid' => $jobUuid,
            'displayName' => NotifyOnPurchaseOrderApproved::class,
        ];

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('attempts')->andReturn(1);

        $service = app(ChainListenerRunService::class);
        $service->markFailed(new JobFailed(
            'redis',
            $job,
            new RuntimeException('failed before failed_jobs was persisted'),
        ));

        $run = ChainListenerRun::query()->where('job_uuid', $jobUuid)->firstOrFail();
        $this->assertSame(ChainListenerRun::STATUS_FAILED, $run->status);
        $this->assertSame(ChainListenerRun::OUTCOME_FAILED, $run->outcome_status);
        $this->assertSame($jobUuid, $run->job_uuid);
    }

    public function test_terminal_failure_uses_the_queue_uuid_when_legacy_payload_omits_it(): void
    {
        $outboxId = (string) \Illuminate\Support\Str::uuid();
        $jobUuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'outbox_id' => $outboxId,
            'outbox_event_type' => PurchaseOrderApproved::class,
            'displayName' => NotifyOnPurchaseOrderApproved::class,
        ];

        DB::table('failed_jobs')->insert([
            'uuid' => $jobUuid,
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'exception' => 'legacy payload failure',
            'failed_at' => now(),
        ]);

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('uuid')->andReturn($jobUuid);
        $job->shouldReceive('attempts')->andReturn(1);

        app(ChainListenerRunService::class)->markFailed(new JobFailed(
            'redis',
            $job,
            new RuntimeException('legacy payload failure'),
        ));

        $run = ChainListenerRun::query()->where('outbox_id', $outboxId)->firstOrFail();
        $this->assertSame(ChainListenerRun::STATUS_FAILED, $run->status);
        $this->assertSame($jobUuid, $run->job_uuid);
    }

    public function test_business_outcome_is_preserved_when_the_listener_job_completes(): void
    {
        $outboxId = (string) \Illuminate\Support\Str::uuid();
        $jobUuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'outbox_id' => $outboxId,
            'outbox_event_type' => PurchaseOrderApproved::class,
            'uuid' => $jobUuid,
            'displayName' => NotifyOnPurchaseOrderApproved::class,
        ];

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('attempts')->andReturn(1);

        $service = app(ChainListenerRunService::class);
        $service->markProcessing(new JobProcessing('sync', $job));
        $service->recordOutcome(
            ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
            'operator_review_required',
            'The listener completed its safe handoff and needs an operator decision.',
        );
        $service->markProcessed(new JobProcessed('sync', $job));

        $run = ChainListenerRun::query()->where('job_uuid', $jobUuid)->firstOrFail();
        $this->assertSame(ChainListenerRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(ChainListenerRun::OUTCOME_MANUAL_REQUIRED, $run->outcome_status);
        $this->assertSame('operator_review_required', $run->outcome_code);
        $this->assertSame(
            'The listener completed its safe handoff and needs an operator decision.',
            $run->outcome_message,
        );
        $this->assertNotNull($run->outcome_at);
    }

    public function test_business_outcome_recreates_missing_telemetry_before_queue_completion(): void
    {
        $outboxId = (string) \Illuminate\Support\Str::uuid();
        $jobUuid = (string) \Illuminate\Support\Str::uuid();
        $payload = [
            'outbox_id' => $outboxId,
            'outbox_event_type' => PurchaseOrderApproved::class,
            'uuid' => $jobUuid,
            'displayName' => NotifyOnPurchaseOrderApproved::class,
        ];

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('attempts')->andReturn(1);

        $service = app(ChainListenerRunService::class);
        $service->markProcessing(new JobProcessing('sync', $job));
        ChainListenerRun::query()->where('job_uuid', $jobUuid)->delete();

        $service->recordOutcome(
            ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
            'telemetry_recreated',
            'The telemetry row was recreated before completion.',
        );
        $service->markProcessed(new JobProcessed('sync', $job));

        $run = ChainListenerRun::query()->where('job_uuid', $jobUuid)->firstOrFail();
        $this->assertSame(ChainListenerRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(ChainListenerRun::OUTCOME_MANUAL_REQUIRED, $run->outcome_status);
        $this->assertSame('telemetry_recreated', $run->outcome_code);
    }

    public function test_nested_sync_listener_contexts_do_not_overwrite_the_parent_outcome(): void
    {
        $parentOutboxId = (string) \Illuminate\Support\Str::uuid();
        $parentJobUuid = (string) \Illuminate\Support\Str::uuid();
        $childOutboxId = (string) \Illuminate\Support\Str::uuid();
        $childJobUuid = (string) \Illuminate\Support\Str::uuid();

        $parent = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $parent->shouldReceive('payload')->andReturn([
            'outbox_id' => $parentOutboxId,
            'outbox_event_type' => PurchaseOrderApproved::class,
            'uuid' => $parentJobUuid,
            'displayName' => NotifyOnPurchaseOrderApproved::class,
        ]);
        $parent->shouldReceive('attempts')->andReturn(1);

        $child = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $child->shouldReceive('payload')->andReturn([
            'outbox_id' => $childOutboxId,
            'outbox_event_type' => PurchaseOrderApproved::class,
            'uuid' => $childJobUuid,
            'displayName' => NotifyOnPurchaseOrderApproved::class,
        ]);
        $child->shouldReceive('attempts')->andReturn(1);

        $service = app(ChainListenerRunService::class);
        $service->markProcessing(new JobProcessing('sync', $parent));
        $service->markProcessing(new JobProcessing('sync', $child));
        $service->recordOutcome(ChainListenerRun::OUTCOME_SKIPPED, 'child_safe_noop');
        $service->markProcessed(new JobProcessed('sync', $child));
        $service->recordOutcome(ChainListenerRun::OUTCOME_MANUAL_REQUIRED, 'parent_operator_handoff');
        $service->markProcessed(new JobProcessed('sync', $parent));

        $parentRun = ChainListenerRun::query()->where('job_uuid', $parentJobUuid)->firstOrFail();
        $childRun = ChainListenerRun::query()->where('job_uuid', $childJobUuid)->firstOrFail();

        $this->assertSame(ChainListenerRun::OUTCOME_MANUAL_REQUIRED, $parentRun->outcome_status);
        $this->assertSame('parent_operator_handoff', $parentRun->outcome_code);
        $this->assertSame(ChainListenerRun::OUTCOME_SKIPPED, $childRun->outcome_status);
        $this->assertSame('child_safe_noop', $childRun->outcome_code);
    }
}
