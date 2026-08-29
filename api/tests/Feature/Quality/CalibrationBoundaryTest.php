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

    /**
     * The whole PATCH path had no test anywhere, and it was throwing
     * MassAssignmentException in every non-production environment because the
     * service re-filled the model from its own `toArray()` — which carries
     * `id`, `created_at` and `updated_at`.
     */
    public function test_patch_updates_a_calibration_record(): void
    {
        $manager = $this->userWithPermissions('calibration-patch-manager', [
            'quality.calibration.view',
            'quality.calibration.manage',
        ]);
        $record = CalibrationRecord::create([
            'equipment_code' => 'CAL-PATCH-001',
            'name' => 'Height gauge',
            'frequency_days' => 365,
            'last_calibration_date' => CarbonImmutable::today()->subDays(10)->toDateString(),
            'next_calibration_date' => CarbonImmutable::today()->addDays(355)->toDateString(),
        ]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/quality/calibration/{$record->hash_id}", [
                'equipment_code' => 'CAL-PATCH-001',
                'name' => 'Height gauge (bench 2)',
                'frequency_days' => 180,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Height gauge (bench 2)')
            ->assertJsonPath('data.frequency_days', 180)
            ->assertJsonPath('data.id', $record->hash_id);

        $this->assertDatabaseHas('calibration_records', [
            'id' => $record->id,
            'name' => 'Height gauge (bench 2)',
            'frequency_days' => 180,
        ]);
    }

    public function test_patch_does_not_resurrect_a_stale_snapshot_of_untouched_fields(): void
    {
        $manager = $this->userWithPermissions('calibration-stale-manager', ['quality.calibration.manage']);
        $record = CalibrationRecord::create([
            'equipment_code' => 'CAL-PATCH-002',
            'name' => 'Torque wrench',
            'frequency_days' => 365,
            'responsible' => 'original owner',
        ]);

        // Another writer commits while this request holds an old representation.
        CalibrationRecord::query()->whereKey($record->id)->update(['responsible' => 'newer owner']);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/quality/calibration/{$record->hash_id}", [
                'equipment_code' => 'CAL-PATCH-002',
                'name' => 'Torque wrench',
                'frequency_days' => 365,
                'location' => 'QC lab',
            ])
            ->assertOk()
            ->assertJsonPath('data.responsible', 'newer owner')
            ->assertJsonPath('data.location', 'QC lab');
    }

    public function test_future_last_calibration_date_is_rejected_on_create(): void
    {
        $manager = $this->userWithPermissions('calibration-future-manager', ['quality.calibration.manage']);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/quality/calibration', [
                'equipment_code' => 'CAL-FUTURE-001',
                'name' => 'Vernier caliper',
                'frequency_days' => 365,
                'last_calibration_date' => CarbonImmutable::tomorrow()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_calibration_date']);

        $this->assertDatabaseMissing('calibration_records', ['equipment_code' => 'CAL-FUTURE-001']);
    }

    public function test_next_calibration_date_before_last_is_rejected(): void
    {
        $manager = $this->userWithPermissions('calibration-order-manager', ['quality.calibration.manage']);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/quality/calibration', [
                'equipment_code' => 'CAL-ORDER-001',
                'name' => 'Pin gauge set',
                'frequency_days' => 365,
                'last_calibration_date' => CarbonImmutable::today()->subDays(10)->toDateString(),
                'next_calibration_date' => CarbonImmutable::today()->subDays(20)->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['next_calibration_date']);

        $this->assertDatabaseMissing('calibration_records', ['equipment_code' => 'CAL-ORDER-001']);
    }

    /**
     * The stored pair is the authority on PATCH: a patch that supplies only the
     * next date must still be checked against the persisted last date, or the
     * register can be walked into an inconsistent schedule one field at a time.
     */
    public function test_patch_supplying_only_the_next_date_is_checked_against_the_stored_last_date(): void
    {
        $manager = $this->userWithPermissions('calibration-order-patch-manager', ['quality.calibration.manage']);
        $record = CalibrationRecord::create([
            'equipment_code' => 'CAL-ORDER-002',
            'name' => 'Thread gauge',
            'frequency_days' => 365,
            'last_calibration_date' => CarbonImmutable::today()->subDays(10)->toDateString(),
            'next_calibration_date' => CarbonImmutable::today()->addDays(355)->toDateString(),
        ]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/quality/calibration/{$record->hash_id}", [
                'equipment_code' => 'CAL-ORDER-002',
                'name' => 'Thread gauge',
                'frequency_days' => 365,
                'next_calibration_date' => CarbonImmutable::today()->subDays(30)->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['next_calibration_date']);

        $this->assertSame(
            CarbonImmutable::today()->addDays(355)->toDateString(),
            $record->fresh()->next_calibration_date->toDateString(),
        );
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
