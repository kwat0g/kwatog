<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Common\Models\ScheduledExport;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_permission_cannot_request_an_unregistered_sensitive_field(): void
    {
        $user = $this->userWith('hr.employees.export');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exports/hr.employees/preview?columns=tin')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['columns.0']);
    }

    public function test_export_permission_cannot_request_salary_without_sensitive_capability(): void
    {
        $user = $this->userWith('hr.employees.export');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/exports/hr.employees/preview?columns=monthly_salary')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['columns.0']);
    }

    public function test_export_user_can_create_a_schedule_but_scheduled_view_alone_cannot_bypass_export_permission(): void
    {
        $payload = [
            'name' => 'Active employees',
            'module' => 'hr.employees',
            'columns' => ['employee_no'],
            'filters' => ['status' => 'active'],
            'format' => 'xlsx',
            'frequency' => 'daily',
            'time_of_day' => '06:00',
            'recipients' => ['hr@ogami.test'],
        ];

        $exporter = $this->userWith('hr.employees.export');
        $this->actingAs($exporter, 'sanctum')
            ->postJson('/api/v1/scheduled-exports', $payload)
            ->assertCreated()
            ->assertJsonPath('data.module', 'hr.employees');

        $schedulerOnly = $this->userWith('admin.scheduled_exports.view');
        $this->actingAs($schedulerOnly, 'sanctum')
            ->postJson('/api/v1/scheduled-exports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['module']);
    }

    public function test_background_execution_rejects_columns_changed_after_schedule_creation(): void
    {
        $owner = $this->userWith('hr.employees.export');
        $row = ScheduledExport::create([
            'owner_id' => $owner->id,
            'name' => 'Tampered schedule',
            'module' => 'hr.employees',
            'columns' => ['tin'],
            'filters' => [],
            'format' => 'csv',
            'frequency' => 'daily',
            'time_of_day' => '06:00',
            'recipients' => [$owner->email],
            'next_run_at' => now()->subMinute(),
            'is_active' => true,
        ]);

        $this->artisan('exports:run-due')->assertExitCode(1);

        // The recorded reason must name the rejected column so an operator can
        // repair the schedule without reading the worker log.
        $lastError = strtolower((string) $row->fresh()->last_error);
        $this->assertStringContainsString('tin', $lastError);
        $this->assertStringContainsString('not available', $lastError);
        $this->assertNull($row->fresh()->processing_token);
    }

    private function userWith(string $permissionSlug): User
    {
        $role = Role::create([
            'name' => 'Export test role '.uniqid(),
            'slug' => 'export-test-'.uniqid(),
            'description' => 'Export authorization test role',
        ]);
        $permission = Permission::firstOrCreate(
            ['slug' => $permissionSlug],
            ['name' => $permissionSlug, 'module' => 'test'],
        );
        $role->permissions()->attach($permission);

        return User::factory()->create(['role_id' => $role->id]);
    }
}
