<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\ChainListenerRun;
use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxDispatcher;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Events\YearEndLeaveProcessingRequested;
use App\Modules\Leave\Listeners\RunYearEndLeaveOnRequested;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Models\YearEndLeaveDisposition;
use App\Modules\Leave\Requests\ProcessYearEndLeaveRequest;
use App\Modules\Leave\Services\YearEndLeaveProcessingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YearEndLeaveDurableHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_year_end_request_is_recorded_and_published_as_a_durable_chain_step(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $outbox = app(YearEndLeaveProcessingService::class)->request($user, 2025);

        $this->assertDatabaseHas('event_outbox', [
            'id' => $outbox->getKey(),
            'event_type' => YearEndLeaveProcessingRequested::class,
            'dedupe_key' => 'leave-year-end:2025:'.hash('sha256', 'all'),
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $outbox->getKey(),
            'chain' => 'h2r',
            'entity_type' => 'year_end_leave',
            'entity_id' => 2025,
            'step' => 'disposition',
        ]);
        Queue::assertPushed(DispatchOutboxMessage::class, fn (DispatchOutboxMessage $job): bool => $job->outboxId === $outbox->getKey());

        $event = app(OutboxEventCodec::class)->decode(
            YearEndLeaveProcessingRequested::class,
            (array) $outbox->payload,
        );

        $this->assertInstanceOf(YearEndLeaveProcessingRequested::class, $event);
        $this->assertSame(2025, $event->year);
        $this->assertSame($user->id, $event->runById);
        $this->assertNull($event->leaveTypeIds);
    }

    public function test_repeated_requests_for_the_same_year_are_deduplicated(): void
    {
        Queue::fake();

        $firstUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $secondUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $first = app(YearEndLeaveProcessingService::class)->request($firstUser, 2025);
        $second = app(YearEndLeaveProcessingService::class)->request($secondUser, 2025);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertDatabaseCount('event_outbox', 1);
        $this->assertDatabaseCount('chain_step_runs', 1);
    }

    public function test_active_replacement_actor_can_retry_after_staged_actor_is_deactivated(): void
    {
        Queue::fake([DispatchOutboxMessage::class]);

        $firstUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $replacement = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $employee = Employee::factory()->create(['status' => 'active']);
        $leaveType = LeaveType::create([
            'name' => 'Replacement actor leave',
            'code' => 'RT-'.substr(uniqid(), -5),
            'default_balance' => 10.0,
            'is_paid' => true,
            'is_active' => true,
            'is_convertible_year_end' => false,
        ]);
        EmployeeLeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2025,
            'total_credits' => 10.0,
            'used' => 2.0,
            'remaining' => 8.0,
        ]);

        $first = $this->actingAs($firstUser)
            ->postJson('/api/v1/leaves/process-year-end', ['year' => 2025])
            ->assertAccepted();
        $firstOutboxId = $first->json('outbox_id');

        $firstUser->update(['is_active' => false]);
        app(OutboxDispatcher::class)->dispatch($firstOutboxId);

        $this->assertDatabaseHas('chain_listener_runs', [
            'outbox_id' => $firstOutboxId,
            'outcome_status' => 'manual_required',
            'outcome_code' => 'year_end_automation_actor_unavailable',
        ]);
        $this->assertSame('8.0', (string) EmployeeLeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', 2025)
            ->value('remaining'));
        $this->assertDatabaseMissing('year_end_leave_dispositions', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => 2025,
        ]);

        $retry = $this->actingAs($replacement)
            ->postJson('/api/v1/leaves/process-year-end', ['year' => 2025])
            ->assertAccepted();

        $this->assertNotSame(
            $firstOutboxId,
            $retry->json('outbox_id'),
            'A retry after actor handoff must stage a fresh request for the active replacement actor.',
        );
        $retryOutboxId = $retry->json('outbox_id');
        $retryEvent = app(OutboxEventCodec::class)->decode(
            YearEndLeaveProcessingRequested::class,
            (array) OutboxMessage::query()->findOrFail($retryOutboxId)->payload,
        );
        $this->assertSame($replacement->id, $retryEvent->runById);

        app(OutboxDispatcher::class)->dispatch($retryOutboxId);

        $balance = EmployeeLeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', 2025)
            ->firstOrFail();
        $this->assertSame('0.0', (string) $balance->remaining);
        $this->assertSame('10.0', (string) $balance->used);
        $this->assertSame(
            '8.0',
            (string) YearEndLeaveDisposition::query()
                ->where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', 2025)
                ->value('days_carried'),
        );
        $this->assertDatabaseHas('processed_year_end_leave_types', [
            'leave_type_id' => $leaveType->id,
            'year' => 2025,
            'processed_by' => $replacement->id,
            'employees_count' => 1,
        ]);

        $repeat = $this->actingAs($replacement)
            ->postJson('/api/v1/leaves/process-year-end', ['year' => 2025])
            ->assertAccepted();
        $this->assertSame($retryOutboxId, $repeat->json('outbox_id'));
        $this->assertDatabaseCount('year_end_leave_dispositions', 1);

        $firstRun = ChainListenerRun::query()
            ->where('outbox_id', $firstOutboxId)
            ->firstOrFail();
        $this->actingAs($replacement)
            ->postJson("/api/v1/chain/listener-runs/{$firstRun->id}/resolve", [
                'note' => 'The replacement actor completed the new year-end run.',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolution_status', 'resolved');
    }

    public function test_command_refuses_inactive_configured_and_fallback_automation_actors(): void
    {
        Queue::fake();

        $inactive = User::factory()->create([
            'email' => 'inactive-year-end-automation@example.test',
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => false,
        ]);

        app(SettingsService::class)->set('leave.year_end.automation_user_email', $inactive->email);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

        $this->artisan('leave:process-year-end', ['year' => 2025])
            ->expectsOutputToContain('Cannot dispatch')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('event_outbox', 0);
    }

    #[DataProvider('invalidYearInputs')]
    public function test_api_rejects_malformed_and_out_of_range_years(mixed $year): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        if (is_float($year)) {
            $response = $this->actingAs($user)->call(
                'POST',
                '/api/v1/leaves/process-year-end',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
                json_encode(['year' => $year], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            );
        } else {
            $response = $this->actingAs($user)->postJson('/api/v1/leaves/process-year-end', ['year' => $year]);
        }

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year')
            ->assertJsonPath('errors.year.0', ProcessYearEndLeaveRequest::yearValidationMessage());

        $this->assertDatabaseCount('event_outbox', 0);
    }

    #[DataProvider('supportedYearInputs')]
    public function test_api_accepts_the_supported_boundary_years(mixed $year, int $expectedYear): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/leaves/process-year-end', ['year' => $year])
            ->assertAccepted();

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => YearEndLeaveProcessingRequested::class,
            'dedupe_key' => 'leave-year-end:'.$expectedYear.':'.hash('sha256', 'all'),
        ]);
    }

    #[DataProvider('invalidYearInputs')]
    public function test_command_rejects_malformed_and_out_of_range_years_before_dispatch(mixed $year): void
    {
        $this->artisan('leave:process-year-end', ['year' => $year])
            ->expectsOutput(ProcessYearEndLeaveRequest::yearValidationMessage())
            ->assertExitCode(1);

        $this->assertDatabaseCount('event_outbox', 0);
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidYearInputs(): array
    {
        return [
            'malformed text' => ['abc'],
            'before supported range' => ['2019'],
            'after supported range' => ['2100'],
            'whitespace padded string' => [' 2025 '],
            'decimal-looking string' => ['2025.0'],
            'decimal JSON number' => [2025.0],
            'signed string' => ['+2025'],
            'leading-zero string' => ['02025'],
        ];
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function supportedYearInputs(): array
    {
        return [
            'minimum integer' => [2020, 2020],
            'minimum string' => ['2020', 2020],
            'maximum integer' => [2099, 2099],
            'maximum string' => ['2099', 2099],
        ];
    }

    public function test_api_and_cli_read_the_same_checked_in_validation_contract(): void
    {
        $contract = json_decode(
            (string) file_get_contents(resource_path('contracts/leave-year-end-validation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($contract);
        $this->assertSame($contract['minimum_year'], ProcessYearEndLeaveRequest::minSupportedYear());
        $this->assertSame($contract['maximum_year'], ProcessYearEndLeaveRequest::maxSupportedYear());
        $this->assertSame($contract['canonical_input']['string_pattern'], ProcessYearEndLeaveRequest::canonicalYearPattern());
    }

    public function test_previous_year_option_targets_the_prior_calendar_year(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'email' => 'active-year-end-automation@example.test',
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        app(SettingsService::class)->set('leave.year_end.automation_user_email', $user->email);

        $this->travelTo(now()->setDate(2026, 1, 3)->setTime(0, 0));

        try {
            $this->artisan('leave:process-year-end', ['--previous-year' => true])
                ->assertExitCode(0);

            $this->assertDatabaseHas('event_outbox', [
                'event_type' => YearEndLeaveProcessingRequested::class,
                'dedupe_key' => 'leave-year-end:2025:'.hash('sha256', 'all'),
            ]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_listener_hands_off_when_actor_is_deactivated_after_staging(): void
    {
        Queue::fake();

        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $leaveType = LeaveType::create([
            'name' => 'Year-end safety leave',
            'code' => 'YE-'.substr(uniqid(), -5),
            'default_balance' => 10.0,
            'is_paid' => true,
            'is_active' => true,
            'is_convertible_year_end' => false,
        ]);

        $outbox = app(YearEndLeaveProcessingService::class)->request($user, 2025);
        $user->update(['is_active' => false]);

        $event = app(OutboxEventCodec::class)->decode(
            YearEndLeaveProcessingRequested::class,
            (array) $outbox->payload,
        );

        app(RunYearEndLeaveOnRequested::class)->handle($event);

        $this->assertDatabaseMissing('processed_year_end_leave_types', [
            'leave_type_id' => $leaveType->id,
            'year' => 2025,
        ]);
    }
}
