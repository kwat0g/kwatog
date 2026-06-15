<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Training;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrainingCatalogTest extends TestCase
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

    private function employee(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
    }

    public function test_admin_can_create_training(): void
    {
        $dept = Department::firstOrCreate(['code' => 'WHS'], ['name' => 'Warehouse']);

        $resp = $this->actingAs($this->admin())->postJson('/api/v1/hr/trainings', [
            'name'             => 'Forklift Operation',
            'description'      => 'Annual licence',
            'duration_hours'   => '4.00',
            'validity_months'  => 12,
            'is_certification' => true,
            'department_id'    => $dept->id,
        ]);

        $resp->assertCreated()
            ->assertJsonPath('data.name', 'Forklift Operation')
            ->assertJsonPath('data.validity_months', 12)
            ->assertJsonPath('data.is_certification', true);
        $this->assertDatabaseHas('trainings', ['name' => 'Forklift Operation']);
    }

    public function test_admin_can_update_training(): void
    {
        $t = Training::create(['name' => 'Old', 'is_active' => true]);

        $resp = $this->actingAs($this->admin())
            ->patchJson("/api/v1/hr/trainings/{$t->hash_id}", ['name' => 'New']);

        $resp->assertOk()->assertJsonPath('data.name', 'New');
    }

    public function test_admin_can_list_trainings(): void
    {
        Training::create(['name' => 'A', 'is_active' => true]);
        Training::create(['name' => 'B', 'is_active' => false]);

        $resp = $this->actingAs($this->admin())->getJson('/api/v1/hr/trainings');
        $resp->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_delete_training(): void
    {
        $t = Training::create(['name' => 'Old', 'is_active' => true]);

        $resp = $this->actingAs($this->admin())->deleteJson("/api/v1/hr/trainings/{$t->hash_id}");
        $resp->assertNoContent();
        $this->assertDatabaseMissing('trainings', ['id' => $t->id]);
    }

    public function test_non_manager_cannot_create_training(): void
    {
        $resp = $this->actingAs($this->employee())->postJson('/api/v1/hr/trainings', [
            'name' => 'X',
        ]);
        $resp->assertForbidden();
    }
}
