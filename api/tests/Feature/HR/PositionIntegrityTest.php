<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_active_position_titles_are_unique_per_department(): void
    {
        $department = Department::create([
            'name' => 'Test Department',
            'code' => 'TST-'.substr(uniqid(), -5),
        ]);
        $position = Position::create([
            'title' => 'Operator',
            'department_id' => $department->id,
            'salary_grade' => 'SG-1',
        ]);
        $hr = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'hr_officer')->value('id'),
        ]);

        $this->actingAs($hr, 'sanctum')
            ->postJson('/api/v1/hr/positions', [
                'title' => ' operator ',
                'department_id' => $department->hash_id,
                'salary_grade' => 'SG-1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->assertSame(1, Position::query()->where('department_id', $department->id)->count());
        $this->assertNotNull($position->refresh()->id);
    }

    public function test_api_permission_denials_do_not_expose_debug_details(): void
    {
        config(['app.debug' => true]);
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($employee, 'sanctum')
            ->postJson('/api/v1/hr/departments', [])
            ->assertForbidden()
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('file')
            ->assertJsonMissingPath('line')
            ->assertJsonMissingPath('trace');
    }
}
