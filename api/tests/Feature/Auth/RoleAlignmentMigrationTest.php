<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAlignmentMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_preference_upgrade_restores_only_the_self_service_default(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $permissionId = Permission::where('slug', 'notifications.preferences.manage')->value('id');
        $role = Role::where('slug', 'driver')->firstOrFail();
        $role->permissions()->detach($permissionId);
        $user = User::factory()->create(['role_id' => $role->id]);
        UserPermissionOverride::create([
            'user_id' => $user->id, 'permission_id' => $permissionId, 'type' => 'revoke',
            'reason' => 'No preference changes', 'granted_by' => $user->id,
        ]);
        $migration = require database_path('migrations/2026_10_02_000004_align_notification_preferences_access.php');
        $migration->up();
        $migration->up();
        $this->assertTrue($role->permissions()->where('slug', 'notifications.preferences.manage')->exists());
        $user->flushPermissionsCache();
        $this->assertFalse($user->hasPermission('notifications.preferences.manage'));
        $this->assertFalse($user->hasPermission('supply_chain.view'));
        $this->assertFalse($user->hasPermission('hr.employees.view'));
    }

    public function test_upgrade_is_idempotent_and_preserves_custom_roles_descriptions_and_user_restrictions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $migration = require database_path('migrations/2026_10_02_000003_align_role_clearance_and_payroll_access.php');
        $migration->down();
        // Restore the deployed buyer's old approval grant before exercising extraction.
        Role::where('slug', 'purchasing_officer')->firstOrFail()->permissions()->attach(Permission::where('slug', 'purchasing.po.approve')->value('id'));
        $finance = Role::where('slug', 'finance_officer')->firstOrFail();
        $finance->update(['description' => 'Custom job description']);
        $user = User::factory()->create(['role_id' => $finance->id]);
        UserPermissionOverride::create([
            'user_id' => $user->id, 'permission_id' => Permission::where('slug', 'hr.separation.view')->value('id'),
            'type' => 'revoke', 'reason' => 'Restricted clearance review', 'granted_by' => $user->id,
        ]);
        $custom = Role::create(['slug' => 'custom_reviewer', 'name' => 'Custom Reviewer', 'is_system' => false]);
        $custom->permissions()->sync([Permission::where('slug', 'purchasing.po.approve')->value('id')]);
        $before = $custom->permissions()->pluck('slug')->all();
        $restrictedBuyer = User::factory()->create(['role_id' => Role::where('slug', 'purchasing_officer')->value('id')]);
        UserPermissionOverride::create([
            'user_id' => $restrictedBuyer->id, 'permission_id' => Permission::where('slug', 'purchasing.po.approve')->value('id'),
            'type' => 'revoke', 'reason' => 'Restricted purchase review', 'granted_by' => $user->id,
        ]);
        $migration->up();
        $migration->up();
        $restrictedBuyer->flushPermissionsCache();
        $this->assertFalse($restrictedBuyer->hasPermission('purchasing.po.responses.resolve'));
        $this->assertFalse($restrictedBuyer->hasPermission('purchasing.po.view_all'));
        $user->flushPermissionsCache();
        $this->assertFalse($user->hasPermission('hr.separation.view_all'));
        $this->assertSame('Custom job description', $finance->fresh()->description);
        $this->assertSame($before, $custom->permissions()->pluck('slug')->all());
        foreach (['warehouse_staff', 'maintenance_tech', 'department_head', 'vice_president'] as $slug) {
            $actor = User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
            $this->assertFalse($actor->hasPermission('hr.separation.view_all'), $slug);
        }
        $hr = User::factory()->create(['role_id' => Role::where('slug', 'hr_officer')->value('id')]);
        $this->assertTrue($hr->hasPermission('admin.gov_tables.manage'));
        $this->assertFalse($hr->hasPermission('admin.users.manage'));
        $this->assertFalse($hr->hasPermission('loans.write_off.approve'));
        $buyer = User::factory()->create(['role_id' => Role::where('slug', 'purchasing_officer')->value('id')]);
        $this->assertTrue($buyer->hasPermission('purchasing.po.create'));
        $this->assertTrue($buyer->hasPermission('purchasing.po.view_all'));
        $this->assertTrue($buyer->hasPermission('purchasing.po.responses.resolve'));
        $this->assertFalse($buyer->hasPermission('purchasing.po.approve'));
        $migration->down();
        $this->assertNull(Permission::where('slug', 'hr.separation.view_all')->first());
        $this->assertSame('Custom job description', $finance->fresh()->description);
        $migration->up();
        $buyer->flushPermissionsCache();
        $this->assertTrue($buyer->hasPermission('purchasing.po.view_all'));
        $this->assertTrue($buyer->hasPermission('purchasing.po.responses.resolve'));
    }
}
