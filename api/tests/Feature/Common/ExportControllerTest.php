<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUserWithRole(string $slug): User
    {
        $roleId = Role::query()->where('slug', $slug)->value('id');
        return User::create([
            'name'     => 'T_'.$slug,
            'email'    => 'u_'.$slug.'_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function makeEmployee(string $no = null, string $first = 'Jane'): Employee
    {
        $code = strtoupper(substr(uniqid('E', true), 0, 16));
        $dept = Department::create(['name' => 'Dept '.$code, 'code' => $code]);
        $pos  = Position::create([
            'title' => 'Tester', 'department_id' => $dept->id,
        ]);
        return Employee::create([
            'employee_no'          => $no ?? ('OGM-T-'.uniqid()),
            'first_name'           => $first,
            'last_name'            => 'Doe',
            'birth_date'           => '1995-01-15',
            'gender'               => 'female',
            'civil_status'         => 'single',
            'department_id'        => $dept->id,
            'position_id'          => $pos->id,
            'employment_type'      => 'regular',
            'pay_type'             => 'monthly',
            'date_hired'           => '2026-01-01',
            'basic_monthly_salary' => 30000,
            'status'               => 'active',
        ]);
    }

    public function test_index_lists_known_export_resources(): void
    {
        $this->actingAs($this->makeUserWithRole('hr_officer'))
            ->getJson('/api/v1/exports')
            ->assertOk()
            ->assertJsonFragment(['hr.employees']);
    }

    public function test_unknown_resource_returns_404(): void
    {
        $this->actingAs($this->makeUserWithRole('hr_officer'))
            ->getJson('/api/v1/exports/not.a.resource')
            ->assertStatus(404);
    }

    public function test_export_requires_resource_specific_permission(): void
    {
        // Plain employee has no hr.employees.export permission.
        $this->actingAs($this->makeUserWithRole('employee'))
            ->get('/api/v1/exports/hr.employees?format=csv')
            ->assertStatus(403);
    }

    public function test_hr_officer_can_download_employees_csv(): void
    {
        $this->makeEmployee('OGM-001', 'Alice');
        $this->makeEmployee('OGM-002', 'Bob');

        $resp = $this->actingAs($this->makeUserWithRole('hr_officer'))
            ->get('/api/v1/exports/hr.employees?format=csv');

        $resp->assertOk();
        $this->assertStringContainsString('text/csv', $resp->headers->get('Content-Type'));

        $body = $resp->streamedContent();
        $this->assertStringContainsString('Employee No', $body);
        $this->assertStringContainsString('OGM-001', $body);
        $this->assertStringContainsString('OGM-002', $body);
        $this->assertStringContainsString('Alice', $body);
        $this->assertStringContainsString('Bob', $body);
    }

    public function test_unsupported_format_returns_422(): void
    {
        $this->actingAs($this->makeUserWithRole('hr_officer'))
            ->get('/api/v1/exports/hr.employees?format=xlsx')
            ->assertStatus(422);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->get('/api/v1/exports/hr.employees')
            ->assertStatus(401);
    }

    public function test_search_filter_narrows_export_rows(): void
    {
        $this->makeEmployee('OGM-A', 'Alice');
        $this->makeEmployee('OGM-B', 'Bob');

        $body = $this->actingAs($this->makeUserWithRole('hr_officer'))
            ->get('/api/v1/exports/hr.employees?format=csv&search=Alice')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Alice', $body);
        $this->assertStringNotContainsString('Bob', $body);
    }
}
