<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Maintenance\Events\PreventiveMaintenanceGenerationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PreventiveMaintenanceDurableHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_maintenance_generation_is_staged_in_the_outbox(): void
    {
        Queue::fake();

        $this->artisan('maintenance:request-preventive-generation', [
            '--request-id' => '2026-08-11',
        ])
            ->expectsOutputToContain('Staged durable preventive-maintenance generation request 2026-08-11')
            ->assertExitCode(0);

        $outbox = OutboxMessage::query()->firstOrFail();
        $this->assertSame(PreventiveMaintenanceGenerationRequested::class, $outbox->event_type);
        $this->assertSame('maintenance-preventive:2026-08-11', $outbox->dedupe_key);
        Queue::assertPushed(DispatchOutboxMessage::class, fn (DispatchOutboxMessage $job): bool => $job->outboxId === $outbox->getKey());

        $event = app(OutboxEventCodec::class)->decode(
            PreventiveMaintenanceGenerationRequested::class,
            (array) $outbox->payload,
        );

        $this->assertInstanceOf(PreventiveMaintenanceGenerationRequested::class, $event);
        $this->assertSame('2026-08-11', $event->requestId);
    }

    public function test_repeated_daily_maintenance_requests_are_deduplicated_and_force_can_requeue(): void
    {
        Queue::fake();

        $this->artisan('maintenance:request-preventive-generation', [
            '--request-id' => '2026-08-11',
        ])->assertExitCode(0);
        $this->artisan('maintenance:request-preventive-generation', [
            '--request-id' => '2026-08-11',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('event_outbox', 1);

        $this->artisan('maintenance:request-preventive-generation', [
            '--request-id' => '2026-08-11',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('event_outbox', 2);
    }
}
