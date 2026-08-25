<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M025 — the COA API exposes view, metadata-manage, and status permissions as
 * separate authorities. Keep the HTTP boundary aligned with the SPA matrix.
 */
class ChartOfAccountsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    public function test_view_only_can_read_but_cannot_change_metadata_or_status(): void
    {
        $user = $this->userWith(['accounting.coa.view']);
        $account = $this->leafAccount();

        $this->actingAs($user)
            ->getJson('/api/v1/accounts')
            ->assertOk();

        $this->actingAs($user)
            ->putJson('/api/v1/accounts/'.$account->hash_id, ['name' => 'Changed'])
            ->assertForbidden();

        $this->actingAs($user)
            ->deleteJson('/api/v1/accounts/'.$account->hash_id)
            ->assertForbidden();
    }

    public function test_manage_only_can_edit_metadata_but_not_change_status(): void
    {
        $user = $this->userWith(['accounting.coa.manage']);
        $account = $this->leafAccount();

        $this->actingAs($user)
            ->putJson('/api/v1/accounts/'.$account->hash_id, ['name' => 'Renamed cash'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed cash');

        $this->actingAs($user)
            ->deleteJson('/api/v1/accounts/'.$account->hash_id)
            ->assertForbidden();

        $this->actingAs($user)
            ->patchJson('/api/v1/accounts/'.$account->hash_id.'/activate')
            ->assertForbidden();
    }

    public function test_status_only_can_deactivate_and_activate_without_metadata_access(): void
    {
        $user = $this->userWith(['accounting.coa.deactivate']);
        $account = $this->leafAccount();

        $this->actingAs($user)
            ->deleteJson('/api/v1/accounts/'.$account->hash_id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($account->fresh()->is_active);

        $this->actingAs($user)
            ->patchJson('/api/v1/accounts/'.$account->hash_id.'/activate')
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->actingAs($user)
            ->putJson('/api/v1/accounts/'.$account->hash_id, ['name' => 'Not allowed'])
            ->assertForbidden();
    }

    public function test_full_coa_access_can_edit_and_change_status(): void
    {
        $user = $this->userWith([
            'accounting.coa.view',
            'accounting.coa.manage',
            'accounting.coa.deactivate',
        ]);
        $account = $this->leafAccount();

        $this->actingAs($user)
            ->putJson('/api/v1/accounts/'.$account->hash_id, ['name' => 'Fully managed cash'])
            ->assertOk();

        $this->actingAs($user)
            ->deleteJson('/api/v1/accounts/'.$account->hash_id)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    /** @param array<int, string> $permissionSlugs */
    private function userWith(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'M025 '.uniqid(),
            'slug' => 'm025_'.uniqid(),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id')->all(),
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function leafAccount(): Account
    {
        return Account::query()->where('code', '1010')->firstOrFail();
    }
}
