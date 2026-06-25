<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\Skill;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function hrUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);
    }

    private function regularUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
    }

    public function test_training_matrix_returns_employee_skill_grid(): void
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $emp = Employee::factory()->create(['department_id' => $dept->id]);

        $skill1 = Skill::create(['name' => 'Forklift', 'category' => 'Safety', 'is_active' => true]);
        $skill2 = Skill::create(['name' => 'Welding', 'category' => 'Technical', 'is_active' => true]);

        EmployeeSkill::create([
            'employee_id'       => $emp->id,
            'skill_id'          => $skill1->id,
            'proficiency_level' => 'competent',
            'acquired_date'     => '2026-01-01',
        ]);

        $resp = $this->actingAs($this->admin())
            ->getJson('/api/v1/hr/training/matrix');

        $resp->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'skills' => [['id', 'name', 'category']],
                    'rows' => [['employee_id', 'employee_name', 'department', 'cells']],
                    'summary' => ['total_employees', 'total_skills', 'trained_count', 'gap_count', 'expired_count'],
                ],
            ]);

        $data = $resp->json('data');

        // Should have 2 skills in header
        $this->assertCount(2, $data['skills']);

        // Employee should have cells for each skill
        $row = collect($data['rows'])->firstWhere('employee_id', $emp->hash_id);
        $this->assertNotNull($row);
        $this->assertCount(2, $row['cells']);

        // First skill (Forklift — Safety sorts before Technical) should be trained
        $forkliftCell = collect($row['cells'])->firstWhere('status', 'trained');
        $this->assertNotNull($forkliftCell);
        $this->assertEquals('competent', $forkliftCell['level']);

        // Second skill (Welding) should be gap
        $gapCell = collect($row['cells'])->firstWhere('status', 'gap');
        $this->assertNotNull($gapCell);
    }

    public function test_training_matrix_filters_by_department(): void
    {
        $dept1 = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $dept2 = Department::firstOrCreate(['code' => 'QC1'], ['name' => 'Quality']);

        $emp1 = Employee::factory()->create(['department_id' => $dept1->id]);
        $emp2 = Employee::factory()->create(['department_id' => $dept2->id]);

        Skill::create(['name' => 'Safety', 'is_active' => true]);

        // Filter by dept1 — only emp1 should appear
        $resp = $this->actingAs($this->admin())
            ->getJson("/api/v1/hr/training/matrix?department_id={$dept1->id}");

        $resp->assertOk();
        $rows = $resp->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($emp1->hash_id, $rows[0]['employee_id']);
    }

    public function test_training_matrix_filters_by_department_hash_id(): void
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $emp = Employee::factory()->create(['department_id' => $dept->id]);
        Employee::factory()->create(); // different dept

        Skill::create(['name' => 'Safety', 'is_active' => true]);

        $resp = $this->actingAs($this->admin())
            ->getJson("/api/v1/hr/training/matrix?department_id={$dept->hash_id}");

        $resp->assertOk();
        $rows = $resp->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($emp->hash_id, $rows[0]['employee_id']);
    }

    public function test_training_matrix_identifies_expired_skills(): void
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $emp = Employee::factory()->create(['department_id' => $dept->id]);
        $skill = Skill::create(['name' => 'Forklift', 'is_active' => true]);

        EmployeeSkill::create([
            'employee_id'       => $emp->id,
            'skill_id'          => $skill->id,
            'proficiency_level' => 'competent',
            'acquired_date'     => '2025-01-01',
            'expires_at'        => Carbon::yesterday()->toDateString(),
        ]);

        $resp = $this->actingAs($this->admin())
            ->getJson('/api/v1/hr/training/matrix');

        $resp->assertOk();
        $row = collect($resp->json('data.rows'))->firstWhere('employee_id', $emp->hash_id);
        $cell = $row['cells'][0];
        $this->assertEquals('expired', $cell['status']);
        $this->assertEquals(1, $resp->json('data.summary.expired_count'));
    }

    public function test_training_matrix_requires_permission(): void
    {
        $resp = $this->actingAs($this->regularUser())
            ->getJson('/api/v1/hr/training/matrix');

        $resp->assertForbidden();
    }

    public function test_training_matrix_requires_authentication(): void
    {
        $resp = $this->getJson('/api/v1/hr/training/matrix');
        $resp->assertUnauthorized();
    }

    public function test_training_matrix_summary_counts_are_correct(): void
    {
        $dept = Department::firstOrCreate(['code' => 'PRD'], ['name' => 'Production']);
        $emp1 = Employee::factory()->create(['department_id' => $dept->id]);
        $emp2 = Employee::factory()->create(['department_id' => $dept->id]);

        $skill1 = Skill::create(['name' => 'Forklift', 'is_active' => true]);
        $skill2 = Skill::create(['name' => 'Welding', 'is_active' => true]);

        // emp1 has skill1 (trained), missing skill2 (gap)
        EmployeeSkill::create([
            'employee_id' => $emp1->id, 'skill_id' => $skill1->id,
            'proficiency_level' => 'expert', 'acquired_date' => '2026-01-01',
        ]);

        // emp2 has skill2 (expired), missing skill1 (gap)
        EmployeeSkill::create([
            'employee_id' => $emp2->id, 'skill_id' => $skill2->id,
            'proficiency_level' => 'novice', 'acquired_date' => '2025-01-01',
            'expires_at' => Carbon::yesterday()->toDateString(),
        ]);

        $resp = $this->actingAs($this->admin())
            ->getJson('/api/v1/hr/training/matrix');

        $resp->assertOk();
        $summary = $resp->json('data.summary');

        $this->assertEquals(2, $summary['total_employees']);
        $this->assertEquals(2, $summary['total_skills']);
        $this->assertEquals(1, $summary['trained_count']);
        $this->assertEquals(2, $summary['gap_count']);
        $this->assertEquals(1, $summary['expired_count']);
    }
}
