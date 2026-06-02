<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Services\BadgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BadgeRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_definitions_include_payroll_work_orders_deliveries_keys(): void
    {
        $user = \App\Modules\Auth\Models\User::factory()->create();
        $svc = app(BadgeService::class);
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('definitions');
        $m->setAccessible(true);
        $defs = $m->invoke($svc, $user);

        foreach (['payroll', 'work_orders', 'deliveries'] as $key) {
            $this->assertArrayHasKey($key, $defs, "Missing badge definition: {$key}");
        }
    }

    public function test_touch_bumps_global_version_so_cache_recomputes(): void
    {
        $user = \App\Modules\Auth\Models\User::factory()->create();
        $svc = app(BadgeService::class);

        $svc->for($user); // primes cache at version v
        $v1 = (int) \Illuminate\Support\Facades\Cache::get('badges.version', 1);

        BadgeService::touch();
        $v2 = (int) \Illuminate\Support\Facades\Cache::get('badges.version', 1);

        $this->assertSame($v1 + 1, $v2);
    }

    public function test_badges_changed_broadcasts_on_private_badges_channel(): void
    {
        $event = new \App\Modules\Dashboard\Events\BadgesChanged();
        $channels = $event->broadcastOn();
        $this->assertSame('private-badges', $channels[0]->name);
        $this->assertSame('BadgesChanged', $event->broadcastAs());
    }

    public function test_creating_a_badge_relevant_model_touches_version_and_fires_event(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Modules\Dashboard\Events\BadgesChanged::class]);
        $v1 = \App\Modules\Dashboard\Services\BadgeService::version();

        // Build the FK chain ProfileUpdateRequest requires: department → position,
        // employee, and a requesting user.
        $deptId = \Illuminate\Support\Facades\DB::table('departments')->insertGetId([
            'name' => 'Production', 'code' => 'PROD', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $positionId = \Illuminate\Support\Facades\DB::table('positions')->insertGetId([
            'title' => 'Operator', 'department_id' => $deptId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $employeeId = \Illuminate\Support\Facades\DB::table('employees')->insertGetId([
            'employee_no' => 'OGM-TEST-0001', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'department_id' => $deptId, 'position_id' => $positionId,
            'employment_type' => 'regular', 'pay_type' => 'monthly', 'date_hired' => '2024-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = \App\Modules\Auth\Models\User::factory()->create();

        // ProfileUpdateRequest backs the `profile_requests` badge.
        \App\Modules\HR\Models\ProfileUpdateRequest::query()->create([
            'employee_id'  => $employeeId,
            'requested_by' => $user->id,
            'status'       => 'pending',
            'changes'      => ['mobile_number' => '09170000000'],
        ]);

        $this->assertSame($v1 + 1, \App\Modules\Dashboard\Services\BadgeService::version());
        \Illuminate\Support\Facades\Event::assertDispatched(\App\Modules\Dashboard\Events\BadgesChanged::class);
    }
}
