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

    public function test_override_permission_allows_self_approve(): void
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

        $approved = $svc->approve($request, $requester->fresh());

        $this->assertSame('approved', $approved->status);
    }
}
