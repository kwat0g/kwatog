<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\CalibrationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalibrationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_calibration_date_is_rejected_at_the_http_boundary(): void
    {
        $manager = $this->userWithPermissions('calibration-manager', [
            'quality.calibration.view',
            'quality.calibration.manage',
        ]);
        $record = CalibrationRecord::create([
            'equipment_code' => 'CAL-BOUNDARY-001',
            'name' => 'Digital caliper',
            'frequency_days' => 365,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/quality/calibration/{$record->hash_id}/record", [
                'date' => CarbonImmutable::tomorrow()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    public function test_explicit_null_frequency_is_rejected_before_database_write(): void
    {
        $manager = $this->userWithPermissions('calibration-create-manager', ['quality.calibration.manage']);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/quality/calibration', [
                'equipment_code' => 'CAL-BOUNDARY-002',
                'name' => 'Micrometer',
                'frequency_days' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['frequency_days']);

        $this->assertDatabaseMissing('calibration_records', ['equipment_code' => 'CAL-BOUNDARY-002']);
    }

    public function test_calibration_list_requires_view_permission(): void
    {
        $user = $this->userWithPermissions('quality-only-viewer', ['quality.view']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/quality/calibration')
            ->assertForbidden();
    }

    private function userWithPermissions(string $slug, array $permissions): User
    {
        $role = Role::create(['name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug]);
        $permissionIds = [];
        foreach ($permissions as $permissionSlug) {
            $permissionIds[] = Permission::firstOrCreate(
                ['slug' => $permissionSlug],
                ['name' => $permissionSlug, 'module' => 'quality'],
            )->id;
        }
        $role->permissions()->sync($permissionIds);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
