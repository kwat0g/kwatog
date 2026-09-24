<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeDecisionPolicy;
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
        $this->assertContains($alreadyApproved->hash_id, $failedIds);
        $this->assertContains(app('hashids')->encode(999999), $failedIds);
        $this->assertNotContains($alreadyApproved->id, $failedIds);
        $this->assertNotContains(999999, $failedIds);

        $this->assertSame(OvertimeStatus::Approved, $okA->fresh()->status);
        $this->assertSame(OvertimeStatus::Approved, $okB->fresh()->status);
    }

    public function test_bulk_approve_hides_unexpected_exception_details(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
        ]);

        $approver = User::factory()->create([
            'role_id' => \App\Modules\Auth\Models\Role::where('slug', 'system_admin')->value('id'),
        ]);
        $pending = $this->makeOt($this->makeEmployee(), OvertimeStatus::Pending);

        $this->instance(OvertimeDecisionPolicy::class, new class extends OvertimeDecisionPolicy
        {
            public function assertNotSelfDecision(OvertimeRequest $overtime, User $actor): void
            {
                throw new \RuntimeException('SQLSTATE[23505]: duplicate key in internal_table');
            }
        });

        $result = app(OvertimeService::class)->bulkApprove([$pending->id], $approver);

        $this->assertCount(1, $result['failed']);
        $this->assertSame($pending->hash_id, $result['failed'][0]['id']);
        $this->assertSame(
            'An unexpected error stopped this request. It has been logged for support.',
            $result['failed'][0]['reason'],
        );
    }

    private function makeEmployee(): Employee
    {
        return Employee::factory()->create();
    }

    private function makeOt(Employee $emp, OvertimeStatus $status): OvertimeRequest
    {
        $ot = OvertimeRequest::make([
            'employee_id'     => $emp->id,
            'date'            => now()->toDateString(),
            'hours_requested' => 2,
            'reason'          => 'Test',
        ]);
        $ot->status = $status;
        $ot->save();
        return $ot;
    }
}
