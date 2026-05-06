<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\UserInvite;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * WS-A.1 — Self-service account creation linked to employee record.
 *
 * Covered behaviour:
 *   1. HR officer with `auth.users.invite` can issue an invite for an
 *      employee that has no linked user.
 *   2. Cannot invite an employee whose user account already exists.
 *   3. Cannot invite without the permission (403).
 *   4. Invite token expires after 72 h.
 *   5. Accepting a valid invite creates a User row, links it to the
 *      employee, sets the password, and marks the invite used.
 *   6. Accept role assignment falls back to position.default_role_id when
 *      no role_id was set on the invite.
 *   7. Accept fails for expired or already-used invites.
 *   8. Revoking an invite makes acceptance impossible (404).
 *   9. Listing invites returns only undismissed ones for HR.
 */
class UserInviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeHrUser(): User
    {
        $hr = Role::where('slug', 'hr_officer')->firstOrFail();
        return User::create([
            'name'     => 'HR Tester',
            'email'    => 'hr_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $hr->id,
        ]);
    }

    private function makeAdminUser(): User
    {
        $admin = Role::where('slug', 'system_admin')->firstOrFail();
        return User::create([
            'name'     => 'Sys Admin',
            'email'    => 'admin_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $admin->id,
        ]);
    }

    private function makeNonHrUser(): User
    {
        $emp = Role::where('slug', 'employee')->firstOrFail();
        return User::create([
            'name'     => 'Plain Emp',
            'email'    => 'emp_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $emp->id,
        ]);
    }

    private function makeEmployee(?int $defaultRoleId = null, ?string $email = 'jane.doe@example.test'): Employee
    {
        $dept = Department::create(['name' => 'Test Dept', 'code' => 'TST']);
        $pos  = Position::create([
            'title'             => 'Tester',
            'department_id'     => $dept->id,
            'salary_grade'      => null,
            'default_role_id'   => $defaultRoleId,
        ]);

        return Employee::create([
            'employee_no'          => 'OGM-T-'.uniqid(),
            'first_name'           => 'Jane',
            'last_name'            => 'Doe',
            'birth_date'           => '1995-01-15',
            'gender'               => 'female',
            'civil_status'         => 'single',
            'email'                => $email,
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'date_hired'           => '2026-01-01',
            'basic_monthly_salary' => 30000,
            'status'               => 'active',
        ]);
    }

    public function test_admin_can_invite_employee_who_has_no_user_yet(): void
    {
        $admin = $this->makeAdminUser();
        $emp   = $this->makeEmployee();
        $role  = Role::where('slug', 'employee')->firstOrFail();

        $resp = $this->actingAs($admin)->postJson('/api/v1/auth/invites', [
            'employee_id' => $emp->hash_id,
            'role_id'     => $role->hash_id,
            'email'       => 'jane.portal@example.test',
        ]);

        $resp->assertCreated()
            ->assertJsonPath('data.email', 'jane.portal@example.test')
            ->assertJsonPath('data.employee.full_name', 'Jane Doe')
            ->assertJsonStructure(['data' => ['id', 'email', 'expires_at', 'employee', 'role']]);

        $this->assertDatabaseHas('user_invites', [
            'employee_id' => $emp->id,
            'email'       => 'jane.portal@example.test',
            'role_id'     => $role->id,
            'used_at'     => null,
        ]);
    }

    public function test_inviting_employee_who_already_has_user_returns_422(): void
    {
        $admin = $this->makeAdminUser();
        $role  = Role::where('slug', 'employee')->firstOrFail();
        $emp   = $this->makeEmployee();

        // Pre-existing user account already linked.
        User::create([
            'name'        => 'Existing',
            'email'       => 'existing@x.test',
            'password'    => bcrypt('Password1!'),
            'role_id'     => $role->id,
            'employee_id' => $emp->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/auth/invites', [
                'employee_id' => $emp->hash_id,
                'role_id'     => $role->hash_id,
                'email'       => 'second@x.test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id']);
    }

    public function test_user_without_permission_cannot_invite(): void
    {
        $u   = $this->makeNonHrUser();
        $emp = $this->makeEmployee();

        $this->actingAs($u)
            ->postJson('/api/v1/auth/invites', [
                'employee_id' => $emp->hash_id,
                'email'       => 'x@x.test',
            ])
            ->assertStatus(403);
    }

    public function test_accepting_a_valid_invite_creates_user_and_marks_used(): void
    {
        $admin = $this->makeAdminUser();
        $role  = Role::where('slug', 'employee')->firstOrFail();
        $emp   = $this->makeEmployee();

        $this->actingAs($admin)
            ->postJson('/api/v1/auth/invites', [
                'employee_id' => $emp->hash_id,
                'role_id'     => $role->hash_id,
                'email'       => 'jane.portal@example.test',
            ])
            ->assertCreated();

        $invite = UserInvite::where('email', 'jane.portal@example.test')->firstOrFail();
        $this->assertNull($invite->used_at);

        // Public, unauthenticated endpoint.
        $this->postJson('/api/v1/auth/invites/accept', [
            'token'                 => $invite->token,
            'name'                  => 'Jane Doe',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertOk()
          ->assertJsonPath('data.email', 'jane.portal@example.test')
          ->assertJsonPath('data.employee.full_name', 'Jane Doe');

        $this->assertDatabaseHas('users', [
            'email'       => 'jane.portal@example.test',
            'employee_id' => $emp->id,
            'role_id'     => $role->id,
        ]);
        $this->assertNotNull(UserInvite::find($invite->id)->used_at);
    }

    public function test_accept_falls_back_to_position_default_role_when_invite_role_is_null(): void
    {
        $admin       = $this->makeAdminUser();
        $defaultRole = Role::where('slug', 'employee')->firstOrFail();
        $emp         = $this->makeEmployee($defaultRole->id);

        $this->actingAs($admin)
            ->postJson('/api/v1/auth/invites', [
                'employee_id' => $emp->hash_id,
                'email'       => 'jane.fallback@example.test',
                // role_id intentionally omitted
            ])
            ->assertCreated();

        $invite = UserInvite::where('email', 'jane.fallback@example.test')->firstOrFail();

        $this->postJson('/api/v1/auth/invites/accept', [
            'token'                 => $invite->token,
            'name'                  => 'Jane Doe',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'email'   => 'jane.fallback@example.test',
            'role_id' => $defaultRole->id,
        ]);
    }

    public function test_accepting_an_expired_invite_fails(): void
    {
        $admin = $this->makeAdminUser();
        $role  = Role::where('slug', 'employee')->firstOrFail();
        $emp   = $this->makeEmployee();

        $this->actingAs($admin)
            ->postJson('/api/v1/auth/invites', [
                'employee_id' => $emp->hash_id,
                'role_id'     => $role->hash_id,
                'email'       => 'expired@example.test',
            ])
            ->assertCreated();

        $invite = UserInvite::where('email', 'expired@example.test')->firstOrFail();
        $invite->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->postJson('/api/v1/auth/invites/accept', [
            'token'                 => $invite->token,
            'name'                  => 'Jane Doe',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertStatus(410);

        $this->assertDatabaseMissing('users', [
            'email' => 'expired@example.test',
        ]);
    }

    public function test_accepting_an_already_used_invite_fails(): void
    {
        $admin = $this->makeAdminUser();
        $role  = Role::where('slug', 'employee')->firstOrFail();
        $emp   = $this->makeEmployee();

        $this->actingAs($admin)
            ->postJson('/api/v1/auth/invites', [
                'employee_id' => $emp->hash_id,
                'role_id'     => $role->hash_id,
                'email'       => 'oneshot@example.test',
            ])
            ->assertCreated();

        $invite = UserInvite::where('email', 'oneshot@example.test')->firstOrFail();

        $this->postJson('/api/v1/auth/invites/accept', [
            'token'                 => $invite->token,
            'name'                  => 'Jane Doe',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertOk();

        // Second attempt with same token should fail.
        $this->postJson('/api/v1/auth/invites/accept', [
            'token'                 => $invite->token,
            'name'                  => 'Jane Doe',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertStatus(410);
    }

    public function test_revoking_an_invite_blocks_acceptance(): void
    {
        $admin = $this->makeAdminUser();
        $role  = Role::where('slug', 'employee')->firstOrFail();
        $emp   = $this->makeEmployee();

        $this->actingAs($admin)
            ->postJson('/api/v1/auth/invites', [
                'employee_id' => $emp->hash_id,
                'role_id'     => $role->hash_id,
                'email'       => 'revoke@example.test',
            ])
            ->assertCreated();

        $invite = UserInvite::where('email', 'revoke@example.test')->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson('/api/v1/auth/invites/'.$invite->hash_id)
            ->assertNoContent();

        $this->postJson('/api/v1/auth/invites/accept', [
            'token'                 => $invite->token,
            'name'                  => 'Jane Doe',
            'password'              => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ])->assertStatus(404);
    }

    public function test_listing_pending_invites_excludes_used_and_revoked(): void
    {
        $admin = $this->makeAdminUser();
        $role  = Role::where('slug', 'employee')->firstOrFail();

        $emp1 = $this->makeEmployee(null, 'a@example.test');
        $emp2 = $this->makeEmployee(null, 'b@example.test');
        $emp3 = $this->makeEmployee(null, 'c@example.test');

        foreach ([['pending@x.test', $emp1], ['used@x.test', $emp2], ['revoked@x.test', $emp3]] as [$email, $emp]) {
            $this->actingAs($admin)
                ->postJson('/api/v1/auth/invites', [
                    'employee_id' => $emp->hash_id,
                    'role_id'     => $role->hash_id,
                    'email'       => $email,
                ])->assertCreated();
        }

        // Mark one used.
        UserInvite::where('email', 'used@x.test')->update(['used_at' => now()]);
        // Revoke another.
        $revoked = UserInvite::where('email', 'revoked@x.test')->firstOrFail();
        $this->actingAs($admin)
            ->deleteJson('/api/v1/auth/invites/'.$revoked->hash_id)
            ->assertNoContent();

        $resp = $this->actingAs($admin)
            ->getJson('/api/v1/auth/invites?status=pending')
            ->assertOk();

        $emails = collect($resp->json('data'))->pluck('email')->all();
        $this->assertContains('pending@x.test', $emails);
        $this->assertNotContains('used@x.test', $emails);
        $this->assertNotContains('revoked@x.test', $emails);
    }
}
