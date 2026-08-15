<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Empty-gap overlap proof using independent PostgreSQL processes. */
class LeaveOverlapTwoConnectionHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_overlapping_submission_waits_on_employee_then_is_rejected(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $department = (int) DB::table('departments')->insertGetId([
            'name' => 'Leave Harness Department', 'code' => 'LH'.random_int(1000, 9999),
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $position = (int) DB::table('positions')->insertGetId([
            'title' => 'Leave Harness Position', 'department_id' => $department,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $employee = (int) DB::table('employees')->insertGetId([
            'employee_no' => 'LH-'.random_int(100000, 999999), 'first_name' => 'Leave', 'last_name' => 'Harness',
            'birth_date' => '1990-01-01', 'gender' => 'other', 'civil_status' => 'single',
            'department_id' => $department, 'position_id' => $position, 'employment_type' => 'regular',
            'pay_type' => 'monthly', 'date_hired' => '2020-01-01', 'basic_monthly_salary' => 30000,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $leaveType = (int) DB::table('leave_types')->insertGetId([
            'name' => 'Harness Leave', 'code' => 'LH'.random_int(10, 99), 'default_balance' => 10,
            'is_paid' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workflow_definitions')->insert([
            'workflow_type' => 'leave_request', 'name' => 'Harness Leave Workflow',
            'steps' => json_encode([['order' => 1, 'role' => 'department_head'], ['order' => 2, 'role' => 'hr_officer']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $result = tempnam(sys_get_temp_dir(), 'leave-race-');

        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
        DB::beginTransaction();
        DB::table('employees')->where('id', $employee)->lockForUpdate()->first();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $base = config('database.connections.'.config('database.default'));
            config(['database.connections.harness' => $base, 'database.default' => 'harness']);
            DB::connection('harness')->getPdo();
            try {
                LeaveRequest::withoutEvents(fn () => app(LeaveRequestService::class)->submit($employee, [
                    'leave_type_id' => $leaveType, 'start_date' => '2026-10-05', 'end_date' => '2026-10-06',
                    'reason' => 'two-connection overlap harness',
                ]));
                file_put_contents($result, 'success');
            } catch (\Throwable $e) {
                file_put_contents($result, 'error:'.$e->getMessage());
            }
            exit(0);
        }
        usleep(250000);
        $this->assertSame('', (string) @file_get_contents($result), 'Second submission must wait on the employee lock.');
        DB::commit();
        pcntl_waitpid($pid, $status);
        $this->assertSame('success', file_get_contents($result));

        try {
            LeaveRequest::withoutEvents(fn () => app(LeaveRequestService::class)->submit($employee, [
                'leave_type_id' => $leaveType, 'start_date' => '2026-10-06', 'end_date' => '2026-10-07',
                'reason' => 'overlap replay',
            ]));
            $this->fail('The overlapping submission must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('You already have a leave request for these dates.', $e->getMessage());
        }
        $this->assertSame(1, DB::table('leave_requests')->where('employee_id', $employee)->count());
        @unlink($result);
    }
}
