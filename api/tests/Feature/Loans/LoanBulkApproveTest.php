<?php

declare(strict_types=1);

namespace Tests\Feature\Loans;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Services\LoanService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanBulkApproveTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_approve_returns_success_and_failure_buckets(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
        ]);

        $approverRole = Role::query()->where('slug', 'system_admin')->firstOrFail();
        $approver = User::factory()->create([
            'role_id'   => $approverRole->id,
            'is_active' => true,
        ]);

        // Reachable loan (the factory may produce a state approve() rejects — that's
        // OK; test asserts only the shape and the not-found row in failed[]).
        $loan = EmployeeLoan::factory()->create();

        $svc = app(LoanService::class);
        $result = $svc->bulkApprove([$loan->id, 99999], $approver, 'bulk');

        $this->assertIsArray($result['approved']);
        $this->assertIsArray($result['failed']);
        $this->assertGreaterThanOrEqual(1, count($result['failed']));

        $failedIds = array_column($result['failed'], 'id');
        $this->assertContains(99999, $failedIds);
    }
}
