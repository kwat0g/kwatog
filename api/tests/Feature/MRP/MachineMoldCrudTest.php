<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MoldHistory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Machine + Mold master-record CRUD endpoints (audit §3.1 follow-up).
 *
 * The SPA create/edit pages were added later than the API; these tests pin
 * the contracts they depend on: code format + uniqueness, defaults applied
 * server-side (idle / available), mold product_id resolved from a hash ID,
 * and 403 when the caller lacks the manage permission.
 */
class MachineMoldCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->manager = $this->userWith('production_manager', [
            'production.machines.manage',
            'production.molds.manage',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function userWith(string $roleSlug, array $permissions): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    // ─── Machine create/update ────────────────────────────────────────────────

    public function test_manager_can_create_machine_with_defaults(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/mrp/machines', [
            'machine_code' => 'INJ-01',
            'name'         => 'Injection Press 1',
            'tonnage'      => 250,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.machine_code', 'INJ-01')
            ->assertJsonPath('data.status', 'idle');

        $this->assertDatabaseHas('machines', [
            'machine_code'           => 'INJ-01',
            'name'                   => 'Injection Press 1',
            'tonnage'                => 250,
            'status'                 => 'idle',
            'machine_type'           => 'injection_molder',
            'operators_required'     => '1.0',
            'available_hours_per_day' => '16.0',
        ]);
    }

    public function test_machine_code_must_match_format_and_be_unique(): void
    {
        Machine::factory()->create(['machine_code' => 'INJ-01']);

        $this->actingAs($this->manager)->postJson('/api/v1/mrp/machines', [
            'machine_code' => 'inj 01', // lowercase + space
            'name'         => 'Bad code',
        ])->assertStatus(422);

        $this->actingAs($this->manager)->postJson('/api/v1/mrp/machines', [
            'machine_code' => 'INJ-01',
            'name'         => 'Duplicate code',
        ])->assertStatus(422);
    }

    public function test_manager_can_update_machine(): void
    {
        $machine = Machine::factory()->create(['machine_code' => 'INJ-02', 'name' => 'Old name']);

        $response = $this->actingAs($this->manager)->putJson("/api/v1/mrp/machines/{$machine->hash_id}", [
            'name'                => 'New name',
            'tonnage'             => 300,
            'available_hours_per_day' => '20',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New name')
            ->assertJsonPath('data.tonnage', 300)
            ->assertJsonPath('data.available_hours_per_day', '20.0');

        $this->assertDatabaseHas('machines', ['id' => $machine->id, 'name' => 'New name']);
    }

    public function test_machine_write_requires_manage_permission(): void
    {
        $viewer = $this->userWith('employee', []);

        $this->actingAs($viewer)->postJson('/api/v1/mrp/machines', [
            'machine_code' => 'INJ-03',
            'name'         => 'Nope',
        ])->assertStatus(403);

        $machine = Machine::factory()->create();
        $this->actingAs($viewer)->putJson("/api/v1/mrp/machines/{$machine->hash_id}", [
            'name' => 'Nope',
        ])->assertStatus(403);
    }

    // ─── Mold create/update ───────────────────────────────────────────────────

    public function test_manager_can_create_mold_with_hash_product_id_and_history(): void
    {
        $product = Product::factory()->create();

        $response = $this->actingAs($this->manager)->postJson('/api/v1/mrp/molds', [
            'mold_code'                    => 'MD-01',
            'name'                         => 'Wiper Bushing Mold',
            'product_id'                   => $product->hash_id,
            'cavity_count'                 => 4,
            'cycle_time_seconds'           => 30,
            'output_rate_per_hour'         => 480,
            'setup_time_minutes'           => 45,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots'           => 1000000,
            'location'                     => 'Rack A-3',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.mold_code', 'MD-01')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.product.id', $product->hash_id);

        $this->assertDatabaseHas('molds', [
            'mold_code'                    => 'MD-01',
            'product_id'                   => $product->id,
            'cavity_count'                 => 4,
            'status'                       => 'available',
            'location'                     => 'Rack A-3',
        ]);

        $mold = Mold::query()->where('mold_code', 'MD-01')->firstOrFail();
        $this->assertDatabaseHas('mold_history', [
            'mold_id'   => $mold->id,
            'event_type' => 'created',
        ]);
    }

    public function test_mold_rejects_bad_product_hash(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/mrp/molds', [
            'mold_code'                    => 'MD-02',
            'name'                         => 'Bad product',
            'product_id'                   => 'not-a-real-hash',
            'cavity_count'                 => 2,
            'cycle_time_seconds'           => 30,
            'output_rate_per_hour'         => 240,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots'           => 10000,
        ])->assertStatus(422);
    }

    public function test_manager_can_update_mold(): void
    {
        $product = Product::factory()->create();
        $mold = Mold::query()->create([
            'mold_code'                    => 'MD-03',
            'name'                         => 'Old',
            'product_id'                   => $product->id,
            'cavity_count'                 => 2,
            'cycle_time_seconds'           => 30,
            'output_rate_per_hour'         => 240,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots'           => 10000,
            'status'                       => 'available',
        ]);

        $response = $this->actingAs($this->manager)->putJson("/api/v1/mrp/molds/{$mold->hash_id}", [
            'name'     => 'New mold name',
            'location' => 'Rack B-1',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New mold name')
            ->assertJsonPath('data.location', 'Rack B-1');

        $this->assertDatabaseHas('molds', ['id' => $mold->id, 'name' => 'New mold name', 'location' => 'Rack B-1']);
    }

    public function test_mold_write_requires_manage_permission(): void
    {
        $viewer = $this->userWith('employee', []);
        $product = Product::factory()->create();

        $this->actingAs($viewer)->postJson('/api/v1/mrp/molds', [
            'mold_code'                    => 'MD-04',
            'name'                         => 'Nope',
            'product_id'                   => $product->hash_id,
            'cavity_count'                 => 1,
            'cycle_time_seconds'           => 30,
            'output_rate_per_hour'         => 120,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots'           => 10000,
        ])->assertStatus(403);
    }
}
