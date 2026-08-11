<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ChainListenerRun;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintNcrHandoffStatus;
use App\Modules\CRM\Events\ComplaintNcrRequested;
use App\Modules\CRM\Listeners\CreateNcrOnComplaintRequested;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Services\ComplaintService;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComplaintNcrHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_ncr_creation_failure_commits_complaint_with_durable_manual_handoff(): void
    {
        $by = User::factory()->create();
        $ncr = \Mockery::mock(NcrService::class);
        $ncr->shouldReceive('create')
            ->zeroOrMoreTimes()
            ->andThrow(new BusinessRuleException('NCR sequence is not configured.'));
        $this->app->instance(NcrService::class, $ncr);

        $complaint = app(ComplaintService::class)->create($this->payload(), $by);

        $this->assertSame('open', $complaint->status->value);
        $this->assertNull($complaint->ncr_id);
        $this->assertSame(ComplaintNcrHandoffStatus::ManualRequired, $complaint->ncr_handoff_status);

        $outbox = DB::table('event_outbox')
            ->where('event_type', ComplaintNcrRequested::class)
            ->where('dedupe_key', 'complaint-ncr-request:' . $complaint->id)
            ->firstOrFail();
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $outbox->id,
            'chain' => 'crm_quality',
            'entity_type' => 'customer_complaint',
            'entity_id' => $complaint->id,
            'step' => 'ncr_handoff',
        ]);
        $this->assertDatabaseHas('chain_listener_runs', [
            'outbox_id' => $outbox->id,
            'listener_class' => CreateNcrOnComplaintRequested::class,
            'outcome_status' => ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
            'outcome_code' => 'complaint_ncr_manual_required',
        ]);

        $this->expectException(BusinessRuleException::class);
        app(ComplaintService::class)->resolve($complaint->fresh());
    }

    public function test_replay_opens_one_ncr_and_is_idempotent(): void
    {
        $by = User::factory()->create();
        $failingNcr = \Mockery::mock(NcrService::class);
        $failingNcr->shouldReceive('create')
            ->zeroOrMoreTimes()
            ->andThrow(new BusinessRuleException('NCR setup is temporarily unavailable.'));
        $this->app->instance(NcrService::class, $failingNcr);

        $complaint = app(ComplaintService::class)->create($this->payload(), $by);
        $this->app->forgetInstance(NcrService::class);

        $outbox = DB::table('event_outbox')
            ->where('event_type', ComplaintNcrRequested::class)
            ->where('dedupe_key', 'complaint-ncr-request:' . $complaint->id)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode(
            (string) $outbox->event_type,
            json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertInstanceOf(ComplaintNcrRequested::class, $event);

        $listener = app(CreateNcrOnComplaintRequested::class);
        $listener->handle($event);
        $listener->handle($event);

        $recovered = $complaint->fresh();
        $this->assertSame(ComplaintNcrHandoffStatus::Generated, $recovered->ncr_handoff_status);
        $this->assertNotNull($recovered->ncr_id);
        $this->assertSame(1, NonConformanceReport::query()->where('complaint_id', $complaint->id)->count());
    }

    private function payload(): array
    {
        return [
            'customer_id' => Customer::factory()->create()->id,
            'received_date' => now()->toDateString(),
            'severity' => 'medium',
            'description' => 'Customer reported a repeatable surface defect.',
            'affected_quantity' => 4,
        ];
    }
}
