<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TempArchiveRestoreE2eTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_endpoints_bind_and_restore_trashed_records(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'system_admin')->value('id')]);

        $dept = Department::factory()->create();
        $dept->delete();
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/hr/departments/{$dept->hash_id}/restore")
            ->assertOk();
        $this->assertNull(Department::find($dept->id)->deleted_at);

        $pos = Position::factory()->create();
        $pos->delete();
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/hr/positions/{$pos->hash_id}/restore")
            ->assertOk();
        $this->assertNull(Position::find($pos->id)->deleted_at);
    }

    public function test_department_tree_honors_trashed_filter(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'system_admin')->value('id')]);

        $dept = Department::factory()->create();
        $dept->delete();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/hr/departments/tree')
            ->assertJsonMissing(['id' => $dept->hash_id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/hr/departments/tree?trashed=only')
            ->assertJsonPath('data.0.id', $dept->hash_id);
    }
}
