<?php

declare(strict_types=1);

namespace Tests\Feature\Edge;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Edge\Models\EdgeDevice;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgeDeviceAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function adminUser(): User
    {
        $role = Role::query()->where('slug', 'system_admin')->firstOrFail();
        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    public function test_admin_can_create_device(): void
    {
        $r = $this->actingAs($this->adminUser())->postJson('/api/v1/admin/edge-devices', [
            'serial_number' => 'SCAN-001',
            'name'          => 'Receiving Dock Scanner',
            'device_type'   => 'barcode_scanner',
            'location'      => 'Dock A',
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('edge_devices', ['serial_number' => 'SCAN-001']);
    }

    public function test_issue_token_returns_plaintext_with_pinned_abilities(): void
    {
        $admin = $this->adminUser();
        $device = EdgeDevice::create([
            'serial_number' => 'PLC-42',
            'name'          => 'Press 3 Counter',
            'device_type'   => 'plc_counter',
            'location'      => 'Press 3',
        ]);

        $r = $this->actingAs($admin)->postJson("/api/v1/admin/edge-devices/{$device->hash_id}/tokens", [
            'name' => 'press3-deploy',
        ]);
        $r->assertStatus(201);
        $body = $r->json('data');
        $this->assertNotEmpty($body['plaintext_token']);
        $this->assertSame(['edge:output'], $body['abilities']);
    }

    public function test_deactivate_revokes_tokens(): void
    {
        $admin = $this->adminUser();
        $device = EdgeDevice::create([
            'serial_number' => 'PLC-99',
            'name'          => 'X',
            'device_type'   => 'plc_counter',
        ]);
        $device->createToken('a', ['edge:output']);
        $device->createToken('b', ['edge:output']);

        $r = $this->actingAs($admin)->patchJson("/api/v1/admin/edge-devices/{$device->hash_id}/deactivate");
        $r->assertOk();

        $this->assertFalse((bool) $device->fresh()->is_active);
        $this->assertSame(0, $device->tokens()->count());
    }

    public function test_non_admin_cannot_create_device(): void
    {
        $randomRole = Role::query()->where('slug', '!=', 'system_admin')->orderBy('id')->firstOrFail();
        $user = User::factory()->create(['role_id' => $randomRole->id, 'is_active' => true]);

        $this->actingAs($user)->postJson('/api/v1/admin/edge-devices', [
            'serial_number' => 'X', 'name' => 'X', 'device_type' => 'barcode_scanner',
        ])->assertStatus(403);
    }
}
