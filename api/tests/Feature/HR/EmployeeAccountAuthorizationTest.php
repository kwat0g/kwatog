<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeAccountAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    public static function actions(): array
    {
        return [['PATCH', 'reset-password'], ['POST', 'deactivate-account']];
    }

    #[DataProvider('actions')]
    public function test_hr_cannot_change_account_even_with_legacy_permissions(string $method, string $action): void
    {
        $role = Role::where('slug', 'hr_officer')->firstOrFail();
        $role->permissions()->syncWithoutDetaching(Permission::whereIn('slug', [
            'hr.employees.reset_password', 'hr.employees.deactivate_account',
        ])->pluck('id'));
        $actor = User::factory()->create(['role_id' => $role->id]);
        [$employee, $target] = $this->target();
        $password = $target->password;
        $this->createTargetSession($target);

        $this->actingAs($actor)->json($method, "/api/v1/hr/employees/{$employee->hash_id}/{$action}")
            ->assertForbidden();

        $this->assertSame($password, $target->fresh()->password);
        $this->assertTrue($target->fresh()->is_active);
        $this->assertDatabaseHas('sessions', ['id' => 'account-auth-session']);
        Notification::assertNothingSent();
        $this->actingAs($actor)->getJson("/api/v1/hr/employees/{$employee->hash_id}/account-status")->assertOk();
    }

    public static function managers(): array
    {
        return [
            ['system_admin', 'PATCH', 'reset-password'], ['system_admin', 'POST', 'deactivate-account'],
            ['it_support', 'PATCH', 'reset-password'], ['it_support', 'POST', 'deactivate-account'],
        ];
    }

    #[DataProvider('managers')]
    public function test_account_managers_can_change_employee_accounts(string $roleSlug, string $method, string $action): void
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => 'IT Support', 'is_system' => false]);
        if ($roleSlug === 'it_support') {
            $role->permissions()->sync([Permission::where('slug', 'admin.users.manage')->firstOrFail()->id]);
        }
        $actor = User::factory()->create(['role_id' => $role->id]);
        [$employee, $target] = $this->target();
        $password = $target->password;
        $this->createTargetSession($target);

        $response = $this->actingAs($actor)->json($method, "/api/v1/hr/employees/{$employee->hash_id}/{$action}")
            ->assertSuccessful();
        $this->assertDatabaseMissing('sessions', ['id' => 'account-auth-session']);
        if ($action === 'reset-password') {
            $response->assertJsonPath('sent_to', $target->email)->assertJsonMissingPath('temp_password');
            $this->assertNotSame($password, $target->fresh()->password);
            $this->assertTrue($target->fresh()->must_change_password);
        } else {
            $this->assertFalse($target->fresh()->is_active);
        }
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => $target->getMorphClass(), 'model_id' => $target->id, 'user_id' => $actor->id,
            'action' => $action === 'reset-password' ? 'password_reset' : 'deactivated',
        ]);
    }

    #[DataProvider('actions')]
    public function test_it_cannot_change_linked_administrator_account(string $method, string $action): void
    {
        $role = Role::create(['slug' => 'it_support', 'name' => 'IT Support', 'is_system' => false]);
        $role->permissions()->sync([Permission::where('slug', 'admin.users.manage')->firstOrFail()->id]);
        $actor = User::factory()->create(['role_id' => $role->id]);
        [$employee, $target] = $this->target('system_admin');
        $password = $target->password;

        $this->actingAs($actor)->json($method, "/api/v1/hr/employees/{$employee->hash_id}/{$action}")
            ->assertForbidden();
        $this->assertSame($password, $target->fresh()->password);
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_hr_seed_does_not_grant_account_mutation_permissions(): void
    {
        $role = Role::where('slug', 'hr_officer')->firstOrFail();
        $this->assertFalse($role->permissions()->whereIn('slug', [
            'hr.employees.reset_password', 'hr.employees.deactivate_account', 'admin.users.manage',
        ])->exists());
    }

    public function test_upgrade_removes_only_hr_account_grants_and_clears_cached_permissions(): void
    {
        $role = Role::where('slug', 'hr_officer')->firstOrFail();
        $legacy = Permission::whereIn('slug', ['hr.employees.reset_password', 'hr.employees.deactivate_account'])->pluck('id');
        $role->permissions()->syncWithoutDetaching($legacy);
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->assertTrue($actor->hasPermission('hr.employees.reset_password'));
        $before = $role->permissions()->pluck('slug')->sort()->values()->all();
        $administrator = Role::where('slug', 'system_admin')->firstOrFail();
        $adminBefore = $administrator->permissions()->pluck('slug')->sort()->values()->all();
        $migration = require database_path('migrations/2026_10_10_000200_restrict_hr_employee_account_changes.php');

        $migration->up();
        $migration->up();
        $this->assertFalse($actor->fresh()->hasPermission('hr.employees.reset_password'));
        $this->assertFalse($actor->fresh()->hasPermission('hr.employees.deactivate_account'));
        $this->assertTrue($actor->fresh()->hasPermission('hr.employees.account_status'));
        $this->assertSame($adminBefore, $administrator->permissions()->pluck('slug')->sort()->values()->all());

        $migration->down();
        $this->assertSame($before, $role->permissions()->pluck('slug')->sort()->values()->all());
    }

    public function test_administrator_cannot_deactivate_own_linked_account(): void
    {
        [$employee, $actor] = $this->target('system_admin');
        $this->actingAs($actor)->postJson("/api/v1/hr/employees/{$employee->hash_id}/deactivate-account")
            ->assertForbidden();
        $this->assertTrue($actor->fresh()->is_active);
    }

    private function target(string $roleSlug = 'employee'): array
    {
        $employee = Employee::factory()->create();
        $target = User::factory()->create([
            'employee_id' => $employee->id,
            'role_id' => Role::where('slug', $roleSlug)->firstOrFail()->id,
            'is_active' => true,
        ]);
        return [$employee, $target];
    }

    private function createTargetSession(User $target): void
    {
        DB::table('sessions')->insert([
            'id' => 'account-auth-session', 'user_id' => $target->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'phpunit', 'payload' => '', 'last_activity' => time(),
        ]);
    }
}
