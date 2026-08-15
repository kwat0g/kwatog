<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HalfDayLeaveOverlapTest extends TestCase
{
    use RefreshDatabase;

    public function test_am_then_pm_on_same_day_do_not_collide(): void
    {
        [$emp, $type] = $this->makeFixtures();
        $svc = app(LeaveRequestService::class);
        $date = now()->addWeek()->toDateString();

        $svc->submit($emp->id, [
            'start_date'      => $date,
            'end_date'        => $date,
            'leave_type_id'   => $type->id,
            'half_day_period' => 'am',
        ]);

        $second = $svc->submit($emp->id, [
            'start_date'      => $date,
            'end_date'        => $date,
            'leave_type_id'   => $type->id,
            'half_day_period' => 'pm',
        ]);

        $this->assertNotNull($second->id);
        $this->assertSame('0.5', (string) $second->days);
    }

    public function test_am_same_day_as_existing_am_collides(): void
    {
        [$emp, $type] = $this->makeFixtures();
        $svc = app(LeaveRequestService::class);
        $date = now()->addWeek()->toDateString();

        $svc->submit($emp->id, [
            'start_date'      => $date,
            'end_date'        => $date,
            'leave_type_id'   => $type->id,
            'half_day_period' => 'am',
        ]);

        $this->expectException(RuntimeException::class);
        $svc->submit($emp->id, [
            'start_date'      => $date,
            'end_date'        => $date,
            'leave_type_id'   => $type->id,
            'half_day_period' => 'am',
        ]);
    }

    public function test_half_day_collides_with_existing_full_day(): void
    {
        [$emp, $type] = $this->makeFixtures();
        $svc = app(LeaveRequestService::class);
        $date = now()->addWeek()->toDateString();

        $svc->submit($emp->id, [
            'start_date'    => $date,
            'end_date'      => $date,
            'leave_type_id' => $type->id,
        ]);

        $this->expectException(RuntimeException::class);
        $svc->submit($emp->id, [
            'start_date'      => $date,
            'end_date'        => $date,
            'leave_type_id'   => $type->id,
            'half_day_period' => 'pm',
        ]);
    }

    public function test_half_day_must_be_single_date(): void
    {
        [$emp, $type] = $this->makeFixtures();
        $svc = app(LeaveRequestService::class);
        $start = now()->addWeek()->toDateString();
        $end   = now()->addWeek()->addDay()->toDateString();

        $this->expectException(\InvalidArgumentException::class);
        $svc->submit($emp->id, [
            'start_date'      => $start,
            'end_date'        => $end,
            'leave_type_id'   => $type->id,
            'half_day_period' => 'am',
        ]);
    }

    public function test_submit_locks_authoritative_employee_before_overlap_check(): void
    {
        [$emp, $type] = $this->makeFixtures();
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(LeaveRequestService::class)->submit($emp->id, [
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'leave_type_id' => $type->id,
        ]);

        $employeeLock = collect(DB::getQueryLog())->first(
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from "employees"')
                && str_contains(strtolower($query['query']), 'for update')
        );

        $this->assertNotNull(
            $employeeLock,
            'Leave submission must serialize on the employee row so two empty-gap overlap checks cannot both win.'
        );
    }

    private function makeFixtures(): array
    {
        $this->seed([
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);
        $emp  = Employee::factory()->create();
        $type = LeaveType::query()->first();
        return [$emp, $type];
    }
}
