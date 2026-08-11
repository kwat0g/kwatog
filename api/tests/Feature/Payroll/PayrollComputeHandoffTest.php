<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Services\OutboxEventCodec;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Listeners\RunPayrollComputationOnRequested;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollProgressTracker;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayrollComputeHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
    }

    public function test_computation_request_round_trips_through_the_allow_listed_codec(): void
    {
        $period = PayrollPeriod::factory()->create();
        $event = new PayrollComputationRequested($period, null, 'compute-request-1');

        $encoded = app(OutboxEventCodec::class)->encode($event);
        $decoded = app(OutboxEventCodec::class)->decode(
            PayrollComputationRequested::class,
            $encoded['payload'],
        );

        $this->assertInstanceOf(PayrollComputationRequested::class, $decoded);
        $this->assertSame($period->id, $decoded->period->id);
        $this->assertSame('compute-request-1', $decoded->requestId);
    }

    public function test_listener_releases_an_empty_claim_and_replayed_request_is_safe(): void
    {
        Queue::fake();
        $period = PayrollPeriod::factory()->create();
        $period->forceFill([
            'status' => PayrollPeriodStatus::Processing->value,
            'processing_started_at' => now(),
        ])->save();

        $event = new PayrollComputationRequested($period->fresh(), null, 'compute-request-2');
        $listener = app(RunPayrollComputationOnRequested::class);

        $listener->handle(
            $event,
            app(PayrollCalculatorService::class),
            app(PayrollPeriodService::class),
            app(PayrollProgressTracker::class),
        );

        $this->assertSame(PayrollPeriodStatus::Draft, $period->fresh()->status);

        // A duplicate outbox publication sees the terminal state and cannot
        // start a second computation.
        $listener->handle(
            $event,
            app(PayrollCalculatorService::class),
            app(PayrollPeriodService::class),
            app(PayrollProgressTracker::class),
        );

        $this->assertSame(PayrollPeriodStatus::Draft, $period->fresh()->status);
    }

    public function test_listener_serializes_duplicate_requests_per_period(): void
    {
        $period = PayrollPeriod::factory()->create();
        $event = new PayrollComputationRequested($period, null, 'compute-request-3');

        $middleware = app(RunPayrollComputationOnRequested::class)->middleware($event);

        $this->assertCount(1, $middleware);
        $this->assertSame(30, $middleware[0]->releaseAfter);
        $this->assertGreaterThan(
            ProcessPayrollJob::TIMEOUT_SECONDS,
            $middleware[0]->expiresAfter,
        );
    }
}
