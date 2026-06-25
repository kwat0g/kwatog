<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Enums\TransferStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetTransfer;
use App\Modules\Assets\Services\AssetTransferService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);

        $adminRole = Role::where('slug', 'system_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        $finRole = Role::where('slug', 'finance_officer')->firstOrFail();
        $this->requester = User::factory()->create(['role_id' => $finRole->id, 'is_active' => true]);
    }

    public function test_can_create_asset_transfer(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $asset = Asset::create([
            'asset_code' => 'AST-T-001', 'name' => 'Test Laptop', 'category' => 'equipment',
            'acquisition_date' => '2025-01-01', 'acquisition_cost' => '50000.00',
            'useful_life_years' => 5, 'salvage_value' => '5000.00',
            'status' => AssetStatus::Active->value, 'department_id' => $deptA->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-transfers', [
            'asset_id'           => $asset->id,
            'from_department_id' => $deptA->id,
            'to_department_id'   => $deptB->id,
            'reason'             => 'Department restructuring',
            'transfer_date'      => '2026-07-01',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('asset_transfers', ['asset_id' => $asset->id, 'status' => 'pending']);
    }

    public function test_approve_moves_asset_to_new_department(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $asset = Asset::create([
            'asset_code' => 'AST-T-002', 'name' => 'Printer', 'category' => 'equipment',
            'acquisition_date' => '2025-06-01', 'acquisition_cost' => '15000.00',
            'useful_life_years' => 3, 'salvage_value' => '1000.00',
            'status' => AssetStatus::Active->value, 'department_id' => $deptA->id,
        ]);

        $transfer = new AssetTransfer();
        $transfer->fill([
            'transfer_number'    => 'AT-T-001',
            'asset_id'           => $asset->id,
            'from_department_id' => $deptA->id,
            'to_department_id'   => $deptB->id,
            'transfer_date'      => '2026-07-01',
            'requested_by'       => $this->requester->id,
        ]);
        $transfer->forceFill(['status' => TransferStatus::Pending->value])->save();

        $service = app(AssetTransferService::class);
        $result = $service->approve($transfer, $this->admin);

        $this->assertEquals(TransferStatus::Completed, $result->status);
        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'department_id' => $deptB->id]);
    }

    public function test_self_approval_blocked(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $asset = Asset::create([
            'asset_code' => 'AST-T-003', 'name' => 'Monitor', 'category' => 'equipment',
            'acquisition_date' => '2025-01-01', 'acquisition_cost' => '12000.00',
            'useful_life_years' => 5, 'salvage_value' => '1000.00',
            'status' => AssetStatus::Active->value, 'department_id' => $deptA->id,
        ]);

        $transfer = new AssetTransfer();
        $transfer->fill([
            'transfer_number'    => 'AT-T-002',
            'asset_id'           => $asset->id,
            'from_department_id' => $deptA->id,
            'to_department_id'   => $deptB->id,
            'transfer_date'      => '2026-07-01',
            'requested_by'       => $this->admin->id,
        ]);
        $transfer->forceFill(['status' => TransferStatus::Pending->value])->save();

        $service = app(AssetTransferService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot approve a transfer you requested.');
        $service->approve($transfer, $this->admin);
    }

    public function test_wrong_department_rejects_transfer(): void
    {
        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();
        $deptC = Department::factory()->create();
        $asset = Asset::create([
            'asset_code' => 'AST-T-004', 'name' => 'Desk', 'category' => 'furniture',
            'acquisition_date' => '2025-01-01', 'acquisition_cost' => '8000.00',
            'useful_life_years' => 10, 'salvage_value' => '500.00',
            'status' => AssetStatus::Active->value, 'department_id' => $deptA->id,
        ]);

        $service = app(AssetTransferService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not currently in the specified source department');
        $service->create([
            'asset_id'           => $asset->id,
            'from_department_id' => $deptC->id,
            'to_department_id'   => $deptB->id,
            'transfer_date'      => '2026-07-01',
        ]);
    }
}
