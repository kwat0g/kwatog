<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Common\Models\ChainStepRun;
use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxDispatcher;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\OutboxService;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_and_chain_step_roll_back_with_business_transaction(): void
    {
        $po = PurchaseOrder::factory()->create();

        try {
            DB::transaction(function () use ($po): void {
                app(OutboxService::class)->recordForChain(
                    new PurchaseOrderApproved($po),
                    $po,
                    'p2p',
                    'purchase_order',
                    'approved',
                );

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('force rollback', $e->getMessage());
        }

        $this->assertDatabaseCount('event_outbox', 0);
        $this->assertDatabaseCount('chain_step_runs', 0);
    }

    public function test_sync_dispatch_publishes_once_and_records_the_chain_step(): void
    {
        Event::fake([PurchaseOrderApproved::class]);
        $po = PurchaseOrder::factory()->create();

        $message = app(OutboxService::class)->recordForChain(
            new PurchaseOrderApproved($po),
            $po,
            'p2p',
            'purchase_order',
            'approved',
        );

        $message->refresh();
        $step = ChainStepRun::query()->where('outbox_id', $message->getKey())->firstOrFail();

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $message->status);
        $this->assertSame(ChainStepRun::STATUS_PUBLISHED, $step->status);
        $this->assertSame(1, $message->attempts);
        $this->assertNotNull($message->published_at);
        Event::assertDispatched(PurchaseOrderApproved::class);

        // Re-recording the same domain event is a no-op, even when a caller
        // retries after the original synchronous enqueue already succeeded.
        $duplicate = app(OutboxService::class)->recordForChain(
            new PurchaseOrderApproved($po),
            $po,
            'p2p',
            'purchase_order',
            'approved',
        );

        $this->assertSame($message->getKey(), $duplicate->getKey());
        $this->assertDatabaseCount('event_outbox', 1);
        $this->assertDatabaseCount('chain_step_runs', 1);
    }

    public function test_failed_publication_is_visible_and_can_be_requeued(): void
    {
        Event::fake([PurchaseOrderApproved::class]);
        $po = PurchaseOrder::factory()->create();
        $message = app(OutboxService::class)->recordForChain(
            new PurchaseOrderApproved($po),
            $po,
            'p2p',
            'purchase_order',
            'approved',
        );

        // Simulate a message that was persisted but not yet claimed by the
        // after-commit enqueue callback.
        DB::table('event_outbox')
            ->where('id', $message->getKey())
            ->update(['status' => OutboxMessage::STATUS_PENDING, 'published_at' => null]);
        DB::table('chain_step_runs')
            ->where('outbox_id', $message->getKey())
            ->update(['status' => ChainStepRun::STATUS_PENDING, 'completed_at' => null]);

        DB::table('event_outbox')
            ->where('id', $message->getKey())
            ->update([
                'payload' => json_encode([
                    'purchaseOrder' => [
                        '__type' => 'model',
                        'class' => 'App\\MissingModel',
                        'id' => '1',
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);

        try {
            app(OutboxDispatcher::class)->dispatch((string) $message->getKey());
            $this->fail('Corrupt payload should fail publication.');
        } catch (RuntimeException) {
            // The dispatcher leaves the row pending for queue/scheduler retry.
        }

        $message->refresh();
        $this->assertSame(OutboxMessage::STATUS_PENDING, $message->status);
        $this->assertNotNull($message->last_error);

        app(OutboxDispatcher::class)->markFailed(
            (string) $message->getKey(),
            new RuntimeException('dead-letter test'),
        );

        $message->refresh();
        $this->assertSame(OutboxMessage::STATUS_FAILED, $message->status);
        $this->assertSame(
            ChainStepRun::STATUS_FAILED,
            ChainStepRun::query()->where('outbox_id', $message->getKey())->value('status'),
        );

        $result = app(OutboxDispatcher::class)->requeueFailed();
        $this->assertSame(1, $result['requeued']);
        $this->assertSame(
            OutboxMessage::STATUS_PENDING,
            $message->fresh()->status,
        );
        $this->assertSame(
            ChainStepRun::STATUS_PENDING,
            ChainStepRun::query()->where('outbox_id', $message->getKey())->value('status'),
        );
    }

    public function test_stale_processing_message_is_reclaimed_by_dispatcher(): void
    {
        Event::fake([PurchaseOrderApproved::class]);
        $po = PurchaseOrder::factory()->create();
        $message = app(OutboxService::class)->recordForChain(
            new PurchaseOrderApproved($po),
            $po,
            'p2p',
            'purchase_order',
            'approved',
        );

        DB::table('event_outbox')
            ->where('id', $message->getKey())
            ->update([
                'status' => OutboxMessage::STATUS_PROCESSING,
                'locked_at' => now()->subMinutes(11),
            ]);

        app(OutboxDispatcher::class)->dispatch((string) $message->getKey());

        $this->assertSame(
            OutboxMessage::STATUS_PUBLISHED,
            $message->fresh()->status,
        );
        $this->assertSame(2, $message->fresh()->attempts);
        Event::assertDispatched(PurchaseOrderApproved::class);
    }

    public function test_scheduled_dispatch_reclaims_processing_message_with_missing_lease_timestamp(): void
    {
        Event::fake([PurchaseOrderApproved::class]);
        $po = PurchaseOrder::factory()->create();
        $message = app(OutboxService::class)->recordForChain(
            new PurchaseOrderApproved($po),
            $po,
            'p2p',
            'purchase_order',
            'approved',
        );

        DB::table('event_outbox')
            ->where('id', $message->getKey())
            ->update([
                'status' => OutboxMessage::STATUS_PROCESSING,
                'locked_at' => null,
                'published_at' => null,
            ]);

        $this->artisan('outbox:dispatch')
            ->assertSuccessful()
            ->expectsOutputToContain('Enqueued 1 outbox messages.');

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $message->fresh()->status);
        $this->assertSame(2, $message->fresh()->attempts);
    }

    public function test_a_delayed_duplicate_does_not_bypass_outbox_retry_backoff(): void
    {
        Event::fake([PurchaseOrderApproved::class]);
        $po = PurchaseOrder::factory()->create();
        $message = app(OutboxService::class)->recordForChain(
            new PurchaseOrderApproved($po),
            $po,
            'p2p',
            'purchase_order',
            'approved',
        );
        $future = now()->addMinutes(5);

        DB::table('event_outbox')
            ->where('id', $message->getKey())
            ->update([
                'status' => OutboxMessage::STATUS_PENDING,
                'available_at' => $future,
                'published_at' => null,
            ]);

        app(OutboxDispatcher::class)->dispatch((string) $message->getKey());

        $fresh = $message->fresh();
        $this->assertSame(OutboxMessage::STATUS_PENDING, $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertNull($fresh->published_at);
    }

    public function test_a_reclaimed_worker_cannot_overwrite_a_newer_outbox_lease(): void
    {
        $outboxId = (string) Str::uuid();
        DB::table('event_outbox')->insert([
            'id' => $outboxId,
            'event_type' => 'test.event',
            'payload' => '{}',
            'dedupe_key' => 'test-lease-fence',
            'status' => OutboxMessage::STATUS_PROCESSING,
            'attempts' => 1,
            'available_at' => now()->subMinutes(20),
            'locked_at' => now()->subMinutes(11),
            'lease_token' => (string) Str::uuid(),
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(11),
        ]);

        $replacementToken = (string) Str::uuid();
        Event::listen(\stdClass::class, static function () use ($outboxId, $replacementToken): void {
            // Simulate a second worker reclaiming the stale lease while the
            // first worker is returning from the external publication call.
            DB::table('event_outbox')->where('id', $outboxId)->update([
                'status' => OutboxMessage::STATUS_PROCESSING,
                'lease_token' => $replacementToken,
                'locked_at' => now(),
            ]);
        });

        $codec = Mockery::mock(OutboxEventCodec::class);
        $codec->shouldReceive('decode')->once()->andReturn(new \stdClass);

        $dispatcher = new OutboxDispatcher($codec);
        $dispatcher->dispatch($outboxId);

        $message = OutboxMessage::query()->findOrFail($outboxId);
        $this->assertSame(OutboxMessage::STATUS_PROCESSING, $message->status);
        $this->assertSame($replacementToken, $message->lease_token);
        $this->assertNull($message->published_at);
    }
}
