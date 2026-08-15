<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Listeners\RunPayrollComputationOnRequested;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollProgressTracker;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PayrollComputeClaimFencingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, GovernmentTableSeeder::class]);
    }

    public function test_claim_token_is_staged_and_round_trips_with_the_request(): void
    {
        Queue::fake();
        $period = PayrollPeriod::factory()->create();

        $claimed = app(PayrollPeriodService::class)->claimForComputeAndStage($period);
        $payload = json_decode((string) DB::table('event_outbox')
            ->where('event_type', PayrollComputationRequested::class)
            ->value('payload'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($claimed->processing_token);
        $this->assertSame($claimed->processing_token, $payload['claimToken']);
    }

    public function test_stale_worker_cannot_write_failure_marker_or_release_new_owner(): void
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-15',
            'payroll_date' => '2026-01-20',
        ]);
        \App\Modules\HR\Models\Employee::factory()->create([
            'date_hired' => '2025-01-01',
        ]);
        $oldToken = 'old-worker-token';
        $newToken = 'new-worker-token';
        $period->forceFill([
            'status' => PayrollPeriodStatus::Processing->value,
            'processing_started_at' => now(),
            'processing_token' => $oldToken,
        ])->save();

        $calculator = Mockery::mock(PayrollCalculatorService::class);
        $calculator->shouldReceive('computeForEmployee')->once()->andReturnUsing(
            function () use ($period, $newToken): never {
                $period->fresh()->forceFill(['processing_token' => $newToken])->save();
                throw new \RuntimeException('worker lost its claim');
            },
        );

        (new ProcessPayrollJob($period->fresh(), null, $oldToken))
            ->handle($calculator, app(PayrollPeriodService::class), app(PayrollProgressTracker::class));

        $fresh = $period->fresh();
        $this->assertSame(PayrollPeriodStatus::Processing, $fresh->status);
        $this->assertSame($newToken, $fresh->processing_token);
        $this->assertSame(0, Payroll::query()->where('payroll_period_id', $period->id)->count());
    }

    public function test_current_owner_can_release_its_claim_and_clear_the_token(): void
    {
        $period = PayrollPeriod::factory()->create();
        $token = 'current-worker-token';
        $period->forceFill([
            'status' => PayrollPeriodStatus::Processing->value,
            'processing_started_at' => now(),
            'processing_token' => $token,
        ])->save();

        (new ProcessPayrollJob($period->fresh(), null, $token))
            ->handle(
                app(PayrollCalculatorService::class),
                app(PayrollPeriodService::class),
                app(PayrollProgressTracker::class),
            );

        $fresh = $period->fresh();
        $this->assertSame(PayrollPeriodStatus::Draft, $fresh->status);
        $this->assertNull($fresh->processing_token);
        $this->assertNull($fresh->processing_started_at);
    }
}
