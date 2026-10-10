<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * REC-02 — segregation of duties on employee profile-change review.
 * A reviewer may not approve a profile-change request they submitted.
 */
class ProfileUpdateSodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function hrUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);
    }

    public function test_submitter_cannot_self_approve(): void
    {
        $svc = app(ProfileUpdateRequestService::class);
        $requester = $this->hrUser();
        $employee = Employee::factory()->create();

        $request = $svc->submit($employee, $requester, ['mobile_number' => '09170000000']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('segregation of duties');
        $svc->approve($request, $requester);
    }

    public function test_different_reviewer_can_approve(): void
    {
        $svc = app(ProfileUpdateRequestService::class);
        $requester = $this->hrUser();
        $reviewer = $this->hrUser();
        $employee = Employee::factory()->create();

        $request = $svc->submit($employee, $requester, ['mobile_number' => '09170000000']);
        $approved = $svc->approve($request, $reviewer);

        $this->assertSame('approved', $approved->status);
        $this->assertSame((int) $reviewer->id, (int) $approved->reviewed_by);
    }

    public function test_override_permission_does_not_allow_self_approval(): void
    {
        $svc = app(ProfileUpdateRequestService::class);
        $requester = $this->hrUser();
        $employee = Employee::factory()->create();

        $request = $svc->submit($employee, $requester, ['mobile_number' => '09170000000']);

        $perm = Permission::firstOrCreate(
            ['slug' => 'hr.profile_updates.self_review_override'],
            ['name' => 'Review Own Profile-Change Request (override)', 'module' => 'hr']
        );
        UserPermissionOverride::create([
            'user_id'       => $requester->id,
            'permission_id' => $perm->id,
            'type'          => 'grant',
            'granted_by'    => $requester->id,
            'reason'        => 'Test override grant',
        ]);
        $requester->flushPermissionsCache();

        $this->actingAs($requester->fresh())
            ->patchJson("/api/v1/hr/profile-update-requests/{$request->hash_id}/review", ['action' => 'approve'])
            ->assertForbidden();

        $this->assertSame('pending', $request->refresh()->status);
        $this->assertNull($employee->refresh()->mobile_number);
    }

    public function test_system_admin_cannot_self_approve_profile_change(): void
    {
        $service = app(ProfileUpdateRequestService::class);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $employee = Employee::factory()->create();
        $request = $service->submit($employee, $admin, ['mobile_number' => '09170000000']);

        $this->actingAs($admin)
            ->patchJson("/api/v1/hr/profile-update-requests/{$request->hash_id}/review", ['action' => 'approve'])
            ->assertForbidden();

        $this->assertSame('pending', $request->refresh()->status);
        $this->assertNull($employee->refresh()->mobile_number);
    }

    public function test_one_reviewer_cannot_approve_both_legs_of_a_bank_change(): void
    {
        $service = app(ProfileUpdateRequestService::class);
        $requester = $this->hrUser();
        $reviewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        foreach (['hr.employees.view_sensitive', 'hr.employees.edit'] as $slug) {
            $permission = Permission::query()->where('slug', $slug)->firstOrFail();
            UserPermissionOverride::create([
                'user_id'       => $reviewer->id,
                'permission_id' => $permission->id,
                'type'          => 'grant',
                'granted_by'    => $reviewer->id,
                'reason'        => 'SoD integration test',
            ]);
        }
        $reviewer->flushPermissionsCache();
        $employee = Employee::factory()->create();
        $request = $service->submit($employee, $requester, [
            'bank_name' => 'Test Bank',
            'bank_account_no' => '1234567890',
        ]);

        $this->actingAs($reviewer)
            ->patchJson("/api/v1/hr/profile-update-requests/{$request->hash_id}/review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_finance');

        $request->refresh();
        $this->assertSame((int) $reviewer->id, (int) $request->reviewed_by);
        $this->assertNull($request->finance_reviewed_by);
        $this->assertNull($employee->refresh()->bank_name);

        $this->patchJson("/api/v1/hr/profile-update-requests/{$request->hash_id}/finance-review", ['action' => 'approve'])
            ->assertForbidden();

        $request->refresh();
        $this->assertSame('pending_finance', $request->status);
        $this->assertNull($request->finance_reviewed_by);
        $this->assertNull($employee->refresh()->bank_name);
    }

    public function test_finance_cannot_approve_without_recorded_hr_approval(): void
    {
        $service = app(ProfileUpdateRequestService::class);
        $requester = $this->hrUser();
        $hrReviewer = $this->hrUser();
        $financeReviewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        $employee = Employee::factory()->create();
        $request = $service->submit($employee, $requester, [
            'bank_name' => 'Test Bank',
            'bank_account_no' => '1234567890',
        ]);

        $this->actingAs($hrReviewer)
            ->patchJson("/api/v1/hr/profile-update-requests/{$request->hash_id}/review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_finance');

        $request->refresh();
        $this->assertSame((int) $hrReviewer->id, (int) $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertNull($request->finance_reviewed_by);
        $this->assertNull($employee->refresh()->bank_name);

        // Model an inconsistent import after completing the first leg through the API.
        $request->forceFill(['reviewed_by' => null, 'reviewed_at' => null])->save();

        $this->actingAs($financeReviewer)
            ->patchJson("/api/v1/hr/profile-update-requests/{$request->hash_id}/finance-review", ['action' => 'approve'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Finance approval requires a recorded HR review.']);

        $request->refresh();
        $this->assertSame('pending_finance', $request->status);
        $this->assertNull($request->finance_reviewed_by);
        $this->assertNull($employee->refresh()->bank_name);
    }

    public function test_finance_can_continue_after_hr_reviewer_is_soft_deleted(): void
    {
        $service = app(ProfileUpdateRequestService::class);
        $requester = $this->hrUser();
        $hrReviewer = $this->hrUser();
        $financeReviewer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        $employee = Employee::factory()->create();
        $request = $service->submit($employee, $requester, [
            'bank_name' => 'Test Bank',
            'bank_account_no' => '1234567890',
        ]);

        $this->actingAs($hrReviewer)
            ->patchJson("/api/v1/hr/profile-update-requests/{$request->hash_id}/review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_finance');

        $request->refresh();
        $this->assertSame((int) $hrReviewer->id, (int) $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => $request->getMorphClass(),
            'model_id' => $request->id,
            'action' => 'updated',
        ]);
        $this->assertNull($employee->refresh()->bank_name);

        $hrReviewer->delete();
        $this->assertSoftDeleted('users', ['id' => $hrReviewer->id]);
        $request->refresh();
        $this->assertSame('pending_finance', $request->status);
        $this->assertSame((int) $hrReviewer->id, (int) $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);

        $this->actingAs($financeReviewer)
            ->patchJson("/api/v1/hr/profile-update-requests/{$request->hash_id}/finance-review", ['action' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame((int) $financeReviewer->id, (int) $request->finance_reviewed_by);
        $this->assertSame('Test Bank', $employee->refresh()->bank_name);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => $request->getMorphClass(),
            'model_id' => $request->id,
            'user_id' => $financeReviewer->id,
            'action' => 'updated',
        ]);
    }

    public function test_immutable_audit_history_blocks_hard_deleting_its_actor(): void
    {
        $user = $this->hrUser();
        $employee = Employee::factory()->create();
        $this->actingAs($user);
        $request = app(ProfileUpdateRequestService::class)->submit(
            $employee,
            $user,
            ['mobile_number' => '09170000000'],
        );
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'model_type' => $request->getMorphClass(),
            'model_id' => $request->id,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Audit logs are immutable');
        $user->forceDelete();
    }
}
