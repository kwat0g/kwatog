<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\YearEndLeaveProcessingRequested;
use App\Modules\Leave\Listeners\RunYearEndLeaveOnRequested;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\YearEndLeaveProcessingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
