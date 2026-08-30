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

        // The failure rows are echoed verbatim to the caller, so they must carry
        // the obfuscated identifier — a raw employee_loans.id in a response body
        // is an existence oracle, and HashIdFilter::decode accepts bare integers
        // so a caller can probe with primary keys directly.
        $failedIds = array_column($result['failed'], 'id');
        $this->assertContains(app('hashids')->encode(99999), $failedIds);
        $this->assertNotContains(99999, $failedIds, 'bulkApprove must not echo the decoded primary key');
        foreach ($failedIds as $failedId) {
            $this->assertIsString($failedId);
            $this->assertFalse(
                ctype_digit($failedId),
                "bulkApprove leaked a raw integer id: {$failedId}",
            );
        }
    }

    /**
     * The HTTP surface is what an attacker sees. `HashIdFilter::decode` accepts
     * bare integers in every environment, so a caller can post primary keys and
     * read back which ones exist. The response body must therefore never carry
     * a decoded `employee_loans.id`.
     *
     * Asserted on the DECODED payload rather than a substring of the raw body:
     * `json_encode` escapes `/`, so string matching against serialized JSON is
     * unsound.
     */
    public function test_bulk_approve_http_body_never_carries_a_decoded_primary_key(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
        ]);

        $approver = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);

        $loan = EmployeeLoan::factory()->create();

        $response = $this->actingAs($approver)->postJson('/api/v1/loans/bulk-approve', [
            // Deliberately raw integers, which decode() tolerates.
            'ids' => [(string) $loan->id, '999999'],
        ])->assertOk();

        $failed = $response->json('data.failed');
        $this->assertIsArray($failed);
        $this->assertNotEmpty($failed, 'expected at least the unresolvable id to fail');

        foreach ($failed as $row) {
            $this->assertIsString($row['id'], 'failure id must be a HashID string, not an integer');
            $this->assertFalse(
                ctype_digit($row['id']),
                "bulk-approve response leaked a raw primary key: {$row['id']}",
            );
        }

        $reportedIds = array_column($failed, 'id');
        $this->assertNotContains((string) $loan->id, $reportedIds);
        $this->assertNotContains('999999', $reportedIds);
        $this->assertContains(app('hashids')->encode(999999), $reportedIds);
    }
}
