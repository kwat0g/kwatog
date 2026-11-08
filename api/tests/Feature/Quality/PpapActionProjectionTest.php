<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Enums\PpapElementType;
use App\Modules\Quality\Enums\PpapLevel;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapElement;
use App\Modules\Quality\Models\PpapSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PpapActionProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_ppap_list_returns_viewer_scoped_permissions_and_actions(): void
    {
        $submission = PpapSubmission::create([
            'ppap_number' => 'PP-ACTIONS-001',
            'vendor_id' => Vendor::factory()->create()->id,
            'item_id' => Item::factory()->create()->id,
            'ppap_level' => PpapLevel::Level3->value,
            'submission_date' => now()->toDateString(),
            'status' => PpapStatus::Draft->value,
        ]);
        PpapElement::create([
            'ppap_submission_id' => $submission->id,
            'element_type' => PpapElementType::PartSubmissionWarrant->value,
            'status' => 'pending',
        ]);

        $manager = $this->userWithPermissions('ppap-action-manager', [
            'quality.ppap.view',
            'quality.ppap.manage',
        ]);
        $viewer = $this->userWithPermissions('ppap-action-viewer', ['quality.ppap.view']);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/quality/ppap')
            ->assertOk()
            ->assertJsonPath('permissions.create', true)
            ->assertJsonPath('data.0.permissions.manage', true)
            ->assertJsonPath('data.0.available_actions.update', true)
            ->assertJsonPath('data.0.available_actions.submit', true)
            ->assertJsonPath('data.0.available_actions.review', false)
            ->assertJsonPath('data.0.available_actions.approve', false)
            ->assertJsonPath('data.0.elements.0.permissions.view', true)
            ->assertJsonPath('data.0.elements.0.available_actions.update', true);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/quality/ppap')
            ->assertOk()
            ->assertJsonPath('permissions.create', false)
            ->assertJsonPath('data.0.permissions.manage', false)
            ->assertJsonPath('data.0.available_actions.update', false)
            ->assertJsonPath('data.0.available_actions.submit', false)
            ->assertJsonPath('data.0.elements.0.permissions.view', true)
            ->assertJsonPath('data.0.elements.0.available_actions.update', false);
    }

    /** @param list<string> $permissions */
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
