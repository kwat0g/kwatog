<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeBulkApproveTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_approve_partial_success(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
        ]);

        $approver = User::factory()->create([
            'role_id' => \App\Modules\Auth\Models\Role::where('slug', 'system_admin')->value('id'),
        ]);

        $emp = $this->makeEmployee();
        $okA = $this->makeOt($emp, OvertimeStatus::Pending);
        $okB = $this->makeOt($emp, OvertimeStatus::Pending);
        $alreadyApproved = $this->makeOt($emp, OvertimeStatus::Approved);

        $svc = app(OvertimeService::class);
        $result = $svc->bulkApprove([$okA->id, $okB->id, $alreadyApproved->id, 999999], $approver);

        $this->assertCount(2, $result['approved']);
        $this->assertCount(2, $result['failed']);

        $failedIds = array_column($result['failed'], 'id');
        $this->assertContains($alreadyApproved->id, $failedIds);
        $this->assertContains(999999, $failedIds);

        $this->assertSame(OvertimeStatus::Approved, $okA->fresh()->status);
        $this->assertSame(OvertimeStatus::Approved, $okB->fresh()->status);
    }

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create();
    }

    private function makeOt(Employee $emp, OvertimeStatus $status): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id'     => $emp->id,
            'date'            => now()->toDateString(),
            'hours_requested' => 2,
            'reason'          => 'Test',
            'status'          => $status->value,
        ]);
    }
}
