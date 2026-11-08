<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\OutboxMessage;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Events\BadgesChanged;
use App\Modules\Dashboard\Services\BadgeService;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BadgeRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_definitions_include_payroll_work_orders_deliveries_keys(): void
    {
        $user = User::factory()->create();
        $svc = app(BadgeService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('definitions');
        $m->setAccessible(true);
        $defs = $m->invoke($svc, $user);

        foreach (['payroll', 'work_orders', 'deliveries'] as $key) {
            $this->assertArrayHasKey($key, $defs, "Missing badge definition: {$key}");
        }
    }

    public function test_widened_scope_definitions_carry_labels_and_descriptions(): void
    {
        $user = User::factory()->create();
        $svc = app(BadgeService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('definitions');
        $m->setAccessible(true);
        $defs = $m->invoke($svc, $user);

        $widened = [
            'inquiries', 'open_complaints', 'pending_inspections', 'pending_grn',
            'mrb_holds', 'shipments', 'mrp_plans', 'pending_returns',
            'draft_invoices', 'overdue_bills', 'training_upcoming', 'open_postings',
        ];
        foreach ($widened as $key) {
            $this->assertArrayHasKey($key, $defs, "Missing badge definition: {$key}");
            $this->assertNotEmpty($defs[$key]['permissions']);
            $this->assertIsString($defs[$key]['label']);
            $this->assertNotSame('', $defs[$key]['label']);
            $this->assertIsString($defs[$key]['description']);
            $this->assertNotSame('', $defs[$key]['description']);
        }

        // Every legacy key must also carry the metadata so the SPA can render
        // tooltips uniformly.
        foreach (['approvals', 'leaves', 'low_stock', 'unread'] as $key) {
            $this->assertArrayHasKey('label', $defs[$key]);
            $this->assertArrayHasKey('description', $defs[$key]);
        }
    }

    public function test_touch_rotates_global_generation_so_cache_recomputes(): void
    {
        $user = User::factory()->create();
        $svc = app(BadgeService::class);

        $svc->for($user); // primes cache at version v
        $v1 = (int) Cache::get('badges.version', 1);
        $widgetV1 = DashboardCacheGeneration::current();

        BadgeService::touch();
        $v2 = (int) Cache::get('badges.version', 1);

        $this->assertNotSame($v1, $v2);
        $this->assertNotSame($widgetV1, DashboardCacheGeneration::current());
    }

    public function test_badge_version_metadata_expires_after_seven_days(): void
    {
        Cache::forget('badges.version');

        BadgeService::touch();
        $this->assertTrue(Cache::has('badges.version'));

        $this->travel(8)->days();
        $this->assertFalse(Cache::has('badges.version'));
    }

    public function test_expired_badge_generation_is_not_reused_after_metadata_expiry(): void
    {
        Cache::put('badges.version', 2, 1);
        Cache::put('badges.user.999.v2', ['stale-generation'], 30);

        $this->travel(2)->seconds();
        $this->assertFalse(Cache::has('badges.version'));

        BadgeService::touch();

        $this->assertNotSame('2', (string) BadgeService::version());
    }

    public function test_badges_changed_fans_out_only_to_each_active_users_private_channel(): void
    {
        $first = User::factory()->create(['is_active' => true]);
        $second = User::factory()->create(['is_active' => true]);
        User::factory()->create(['is_active' => false]);

        $event = new BadgesChanged;
        $channels = $event->broadcastOn();
        $this->assertEqualsCanonicalizing([
            "private-badge-user.{$first->hash_id}",
            "private-badge-user.{$second->hash_id}",
        ], array_map(
            static fn ($channel): string => $channel->name,
            $channels,
        ));
        $this->assertSame('BadgesChanged', $event->broadcastAs());
        $this->assertSame(['refresh', 'event_id'], array_keys($event->broadcastWith()));
    }

    public function test_custom_approval_sources_are_observed_for_board_refreshes(): void
    {
        $period = PayrollPeriod::factory()->create();
        $employee = Employee::factory()->create();
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);
        $eventsBeforeCreate = DB::table('event_outbox')
            ->where('event_type', BadgesChanged::class)
            ->where('status', OutboxMessage::STATUS_PENDING)
            ->count();

        $adjustment = PayrollAdjustment::query()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
            'original_payroll_id' => $payroll->id,
            'type' => 'underpayment',
            'amount' => '500.00',
            'reason' => 'Approval board refresh test',
            'created_by' => User::factory()->create()->id,
        ]);

        $eventsAfterCreate = DB::table('event_outbox')
            ->where('event_type', BadgesChanged::class)
            ->where('status', OutboxMessage::STATUS_PENDING)
            ->count();
        $this->assertSame($eventsBeforeCreate + 1, $eventsAfterCreate, 'Creating a custom approval source must stage a board refresh.');

        $eventsBeforeDecision = $eventsAfterCreate;
        $adjustment->forceFill(['status' => 'approved'])->save();

        $this->assertSame($eventsBeforeDecision + 1, DB::table('event_outbox')
            ->where('event_type', BadgesChanged::class)
            ->where('status', OutboxMessage::STATUS_PENDING)
            ->count(), 'A custom approval decision must stage a second board refresh.');
    }

    public function test_creating_a_badge_relevant_model_touches_version_and_stages_refresh_outbox(): void
    {
        $v1 = BadgeService::version();

        // Build the FK chain ProfileUpdateRequest requires: department → position,
        // employee, and a requesting user.
        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Production', 'code' => 'PROD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $positionId = DB::table('positions')->insertGetId([
            'title' => 'Operator', 'department_id' => $deptId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'employee_no' => 'OGM-TEST-0001', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'department_id' => $deptId, 'position_id' => $positionId,
            'employment_type' => 'regular', 'pay_type' => 'monthly', 'date_hired' => '2024-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create();

        // ProfileUpdateRequest backs the `profile_requests` badge.
        ProfileUpdateRequest::query()->create([
            'employee_id' => $employeeId,
            'requested_by' => $user->id,
            'status' => 'pending',
            'changes' => ['mobile_number' => '09170000000'],
        ]);

        $this->assertNotSame($v1, BadgeService::version());
        $this->assertDatabaseHas('event_outbox', [
            'event_type' => BadgesChanged::class,
            'status' => OutboxMessage::STATUS_PENDING,
        ]);
    }

    public function test_badge_refresh_outbox_is_staged_inside_the_source_transaction(): void
    {
        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Transaction Test', 'code' => 'TXN', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $positionId = DB::table('positions')->insertGetId([
            'title' => 'Operator', 'department_id' => $deptId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employeeId = DB::table('employees')->insertGetId([
            'employee_no' => 'OGM-TXN-0001', 'first_name' => 'Test', 'last_name' => 'Operator',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'department_id' => $deptId, 'position_id' => $positionId,
            'employment_type' => 'regular', 'pay_type' => 'monthly', 'date_hired' => '2024-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create();
        $before = DB::table('event_outbox')->where('event_type', BadgesChanged::class)->count();

        DB::beginTransaction();
        try {
            ProfileUpdateRequest::query()->create([
                'employee_id' => $employeeId,
                'requested_by' => $user->id,
                'status' => 'pending',
                'changes' => ['mobile_number' => '09170000001'],
            ]);

            $this->assertSame(
                $before + 1,
                DB::table('event_outbox')->where('event_type', BadgesChanged::class)->count(),
                'The refresh signal must be durable in the source transaction before commit.',
            );
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, DB::table('event_outbox')->where('event_type', BadgesChanged::class)->count());
    }
}
