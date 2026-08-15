<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Assets\Enums\TransferStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetTransfer;
use App\Modules\Assets\Services\AssetTransferService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Asset-transfer approve/reject race — the P01-01 shape on the asset ledger.
 *
 * `reject()` guards `status` on the passed model outside any transaction (and
 * approve() guards it outside its transaction too). A rejection that read the
 * transfer while it was `pending` can land AFTER a concurrent approval already
 * moved the asset to the destination department — leaving the asset relocated
 * while the transfer ledger says Rejected. Both paths must lock and re-read the
 * authoritative row.
 */
class AssetTransferRejectRaceTest extends TestCase
{
    use RefreshDatabase;

    private AssetTransferService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(AssetTransferService::class);
    }

    public function test_reject_after_concurrent_approval_is_blocked(): void
    {
        $requestor = User::factory()->create(['is_active' => true]);
        $approver  = User::factory()->create(['is_active' => true]);
        $deptFrom  = Department::create(['name' => 'From-' . substr(uniqid(), -5), 'code' => 'FR' . substr(uniqid(), -4)]);
        $deptTo    = Department::create(['name' => 'To-' . substr(uniqid(), -5), 'code' => 'TO' . substr(uniqid(), -4)]);
        $asset     = Asset::create([
            'asset_code'        => 'A-' . substr(uniqid(), -8),
            'name'              => 'Test asset',
            'category'          => 'equipment',
            'department_id'     => $deptFrom->id,
            'acquisition_date'  => now()->toDateString(),
            'acquisition_cost'  => 1000,
            'useful_life_years' => 5,
        ]);

        $this->actingAs($requestor);
        $transfer = $this->svc->create([
            'asset_id'          => $asset->id,
            'from_department_id'=> $deptFrom->id,
            'to_department_id'  => $deptTo->id,
            'transfer_date'     => now()->toDateString(),
            'reason'            => 'Reorg',
        ]);
        $this->assertSame(TransferStatus::Pending, $transfer->status);

        // Approver and rejector both read the row while it was pending.
        $approverView = AssetTransfer::query()->findOrFail($transfer->id);
        $rejectorView = AssetTransfer::query()->findOrFail($transfer->id);

        // Approver commits: asset moved to destination, transfer completed.
        $this->svc->approve($approverView, $approver);
        $this->assertSame(TransferStatus::Completed, AssetTransfer::query()->findOrFail($transfer->id)->status);
        $this->assertSame($deptTo->id, (int) Asset::query()->findOrFail($asset->id)->department_id);

        // Rejector acts on its stale pending instance — must be blocked.
        $this->expectException(BusinessRuleException::class);

        $this->svc->reject($rejectorView, $approver);
    }
}
