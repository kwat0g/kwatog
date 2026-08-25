<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Services\BudgetActualsSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BudgetActualsDurableHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sync_request_is_durable_and_replayable(): void
    {
        Queue::fake();
        $fiscalYear = FiscalYear::factory()->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $outbox = app(BudgetActualsSyncService::class)->request($fiscalYear->id);

        $this->assertDatabaseHas('event_outbox', [
            'id' => $outbox->getKey(),
            'event_type' => BudgetActualsSyncRequested::class,
            'status' => 'pending',
        ]);
        Queue::assertPushed(DispatchOutboxMessage::class, fn (DispatchOutboxMessage $job): bool => $job->outboxId === $outbox->getKey());

        $event = app(OutboxEventCodec::class)->decode(
            BudgetActualsSyncRequested::class,
            (array) $outbox->payload,
        );

        $this->assertInstanceOf(BudgetActualsSyncRequested::class, $event);
        $this->assertSame($fiscalYear->id, $event->fiscalYearId);
        $this->assertNotSame('', $event->requestId);
    }

    public function test_duplicate_sync_triggers_in_one_scheduler_tick_share_one_request(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-11 12:00:00');
        $fiscalYear = FiscalYear::factory()->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $first = app(BudgetActualsSyncService::class)->request($fiscalYear->id);
        $second = app(BudgetActualsSyncService::class)->request($fiscalYear->id);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, DB::table('event_outbox')
            ->where('dedupe_key', $first->dedupe_key)
            ->count());

    }
}
