<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvertimeListRoutePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_list_and_options_accept_the_permissions_the_spa_pages_require(): void
    {
        $approver = $this->userWithPermissions('Overtime Approver', ['attendance.ot.approve']);
        $this->assertTrue($approver->hasPermission('attendance.ot.approve'));
        $this->assertFalse($approver->hasPermission('attendance.view'));

        $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/attendance/overtime-requests')
            ->assertOk();
        $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/attendance/overtime-requests/options')
            ->assertOk();

        $creator = $this->userWithPermissions('Overtime Request Creator', ['attendance.ot.create']);
        $this->actingAs($creator, 'sanctum')
            ->getJson('/api/v1/attendance/overtime-requests/options')
            ->assertOk();
        $this->actingAs($creator, 'sanctum')
            ->getJson('/api/v1/attendance/overtime-requests')
            ->assertForbidden();

        $unprivileged = $this->userWithPermissions('No Overtime Access', []);
        $this->actingAs($unprivileged, 'sanctum')
            ->getJson('/api/v1/attendance/overtime-requests')
            ->assertForbidden();
        $this->actingAs($unprivileged, 'sanctum')
            ->getJson('/api/v1/attendance/overtime-requests/options')
            ->assertForbidden();
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(string $name, array $permissions): User
    {
        $role = Role::create(['name' => $name, 'slug' => str($name)->slug('_')->value()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id]);
    }
}
