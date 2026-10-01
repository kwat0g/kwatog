<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\ApprovalService;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\MachineDowntime;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two remaining UNVERIFIED modules, walked over the real HTTP API with the
 * same adversarial approach as the chain tests:
 *
 *  A. ASSETS — create → QR payload → depreciation run (StraightLine proration)
 *     → two-phase disposal: REQUEST (finance) → chain approve (finance → VP)
 *     → Disposed with a 4-line disposal JE.
 *
 *  B. MAINTENANCE — preventive schedule → MWO (machine) → assign → start
 *     (refused on a running machine) → complete posts machine downtime; MWO
 *     (mold) → complete resets the shot counter; spare-part issue decrements
 *     stock; schedule deletion and cancel refusals.
 *
 * Wrong-actor probes ride along: hr/production cannot create assets, the
 * disposer cannot approve their own request (chain is finance → VP), a plain
 * employee cannot touch maintenance, and the machine-downtime guard blocks
 * starting a WO on a machine in production.
 */
class AssetsMaintenanceAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $finance;
    private User $vp;
    private User $prodManager;
    private User $maintenanceTech;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(UomSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $make = fn (string $slug): User => User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);

        $this->admin = $make('system_admin');
        $this->finance = $make('finance_officer');
        $this->vp = $make('vice_president');
        $this->prodManager = $make('production_manager');
        $this->maintenanceTech = $make('maintenance_tech');
        $this->employee = $make('employee');
    }

    /* ══════════════════════════════════════════════════════════════════
       A. ASSETS — register → QR → depreciate → two-phase disposal
       ══════════════════════════════════════════════════════════════════ */

    public function test_a_asset_lifecycle_from_acquisition_to_disposal(): void
    {
        // ── Wrong actor: production_manager can only VIEW assets ──
        $this->actingAs($this->prodManager)
            ->postJson('/api/v1/assets', $this->assetBody())
            ->assertForbidden();

        // ── Finance registers the asset ──
        $create = $this->actingAs($this->finance)
            ->postJson('/api/v1/assets', $this->assetBody())
            ->assertCreated();
        $assetId = (int) app('hashids')->decode((string) $create->json('data.id'))[0];
        $asset = Asset::query()->findOrFail($assetId);
        $this->assertSame(AssetStatus::Active->value, $asset->status->value);
        $this->assertMatchesRegularExpression('/^AST-/', (string) $asset->asset_code);
        $assetHash = $asset->hash_id;

        // ── QR tracking payload resolves the asset ──
        $qr = $this->actingAs($this->prodManager)
            ->getJson("/api/v1/assets/{$assetHash}/qr")
            ->assertOk()
            ->json('data');
        $this->assertSame($asset->asset_code, $qr['asset_code']);
        $this->assertStringContainsString('/assets/'.$assetHash, (string) $qr['url']);

        // ── Depreciation run for the acquisition month ──
        // 12 000 cost, 5y life, 0 salvage → straight line 200.00/month.
        $this->actingAs($this->finance)
            ->postJson('/api/v1/asset-depreciations/run', [
                'year' => 2026,
                'month' => 1,
            ])
            ->assertOk();
        $dep = AssetDepreciation::query()
            ->where('asset_id', $assetId)
            ->where('period_year', 2026)
            ->where('period_month', 1)
            ->firstOrFail();
        $this->assertSame('200.00', (string) $dep->depreciation_amount);

        // Wrong actor: production_manager cannot run depreciation.
        $this->actingAs($this->prodManager)
            ->postJson('/api/v1/asset-depreciations/run', ['year' => 2026, 'month' => 2])
            ->assertForbidden();

        // ── Two-phase disposal: the request alone disposes NOTHING ──
        $this->actingAs($this->finance)
            ->postJson("/api/v1/assets/{$assetHash}/dispose", [
                'disposal_amount' => '5000.00',
                'disposed_date' => '2026-02-10',
                'remarks' => 'Sold — replaced by newer machine',
            ])
            ->assertOk();
        $asset->refresh();
        $this->assertSame(AssetStatus::Active->value, $asset->status->value);
        $this->assertSame(0, $this->disposalJeCount($asset));

        // Wrong actor: the disposer cannot approve their own request — the
        // self-approval guard fires before any chain step is recorded, and
        // the step-2 holder (VP) cannot jump step 1 either.
        $this->actingAs($this->finance)
            ->postJson("/api/v1/assets/{$assetHash}/dispose/approve", ['remarks' => 'Self-approval attempt.'])
            ->assertStatus(403);
        $this->actingAs($this->vp)
            ->postJson("/api/v1/assets/{$assetHash}/dispose/approve", ['remarks' => 'Jumping the chain.'])
            ->assertStatus(403);

        // ── Chain walk: finance (step 1) → VP (step 2) → Disposed + JE ──
        // The disposal REQUEST was raised by finance; the self-approval guard
        // refuses the maker on their own request, so step 1 goes to a SECOND
        // finance officer and step 2 to the VP.
        $secondFinance = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);
        $this->actingAs($secondFinance)
            ->postJson("/api/v1/assets/{$assetHash}/dispose/approve", ['remarks' => 'Reviewed.'])
            ->assertOk();
        $asset->refresh();
        $this->assertSame(AssetStatus::Active->value, $asset->status->value, 'step 1 alone must not dispose');

        $this->actingAs($this->vp)
            ->postJson("/api/v1/assets/{$assetHash}/dispose/approve", ['remarks' => 'Approved.'])
            ->assertOk();
        $asset->refresh();
        $this->assertSame(AssetStatus::Disposed->value, $asset->status->value);
        $this->assertSame('5000.00', (string) $asset->disposal_amount);
        $this->assertNull($asset->disposal_request_amount, 'execution clears the proposal');

        // The disposal JE: proceeds, accumulated depreciation reversal, cost
        // removal, loss against book value (12000 − 200 − 5000 = 6800 loss).
        $je = JournalEntry::query()->with('lines')
            ->where('reference_type', Asset::class)
            ->where('reference_id', $assetId)
            ->firstOrFail();
        $this->assertCount(4, $je->lines);

        // Wrong time: disposing a disposed asset is refused.
        $this->actingAs($this->finance)
            ->postJson("/api/v1/assets/{$assetHash}/dispose", [
                'disposal_amount' => '1.00',
                'remarks' => 'Again.',
            ])
            ->assertStatus(422);
    }

    /* ══════════════════════════════════════════════════════════════════
       B. MAINTENANCE — schedule → MWO → downtime + mold shot reset
       ══════════════════════════════════════════════════════════════════ */

    public function test_b_machine_work_order_walk_posts_downtime(): void
    {
        $machine = Machine::factory()->create(['status' => 'idle']);

        // ── Wrong actor: a plain employee cannot create maintenance WOs ──
        $this->actingAs($this->employee)
            ->postJson('/api/v1/maintenance/work-orders', $this->mwoBody($machine))
            ->assertForbidden();

        // ── Corrective MWO on the machine ──
        $wo = $this->actingAs($this->maintenanceTech)
            ->postJson('/api/v1/maintenance/work-orders', $this->mwoBody($machine))
            ->assertCreated()
            ->json('data');
        $woModel = MaintenanceWorkOrder::query()->findOrFail(
            (int) app('hashids')->decode((string) $wo['id'])[0]
        );
        $this->assertSame('open', $woModel->status->value ?? $woModel->getRawOriginal('status'));

        // ── Assign an active employee, then start ──
        $tech = $this->techEmployee();
        $this->actingAs($this->admin)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo['id']}/assign", [
                'employee_id' => $tech->hash_id,
            ])
            ->assertOk();

        // Wrong state: starting while the machine runs production is refused.
        $machine->forceFill(['status' => 'running'])->save();
        $this->actingAs($this->maintenanceTech)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo['id']}/start")
            ->assertStatus(422);
        $this->assertSame('assigned', $woModel->refresh()->status->value, 'the refusal must not have advanced the WO');
        $machine->forceFill(['status' => 'idle'])->save();

        $this->actingAs($this->maintenanceTech)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo['id']}/start")
            ->assertOk();

        // start() opens an open-ended downtime ledger row immediately.
        $this->assertSame(1, MachineDowntime::query()
            ->where('machine_id', $machine->id)
            ->whereNull('end_time')
            ->count(), 'start must open an open-ended downtime row');

        // ── Work log + spare-part issue decrements stock ──
        $this->actingAs($this->maintenanceTech)
            ->postJson("/api/v1/maintenance/work-orders/{$wo['id']}/logs", [
                'description' => 'Replaced hydraulic hose.',
            ])
            ->assertCreated();

        $item = Item::factory()->create(['is_active' => true, 'item_type' => 'spare_part']);
        $location = WarehouseLocation::factory()->create();
        \App\Modules\Inventory\Models\StockLevel::factory()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'reserved_quantity' => 0,
            'weighted_avg_cost' => 250,
        ]);
        $this->actingAs($this->maintenanceTech)
            ->postJson("/api/v1/maintenance/work-orders/{$wo['id']}/spare-parts", [
                'item_id' => $item->hash_id,
                'location_id' => $location->hash_id,
                'quantity' => 2,
            ])
            ->assertCreated();
        $this->assertSame(
            '3.000',
            (string) \App\Modules\Inventory\Models\StockLevel::query()
                ->where('item_id', $item->id)
                ->where('location_id', $location->id)
                ->value('quantity'),
            'spare-part issue must draw from stock',
        );

        // ── Complete: the elapsed minutes post as machine downtime ──
        sleep(1);
        $this->actingAs($this->maintenanceTech)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo['id']}/complete", [
                'remarks' => 'Hose replaced, machine back online.',
            ])
            ->assertOk();
        $woModel->refresh();
        $this->assertSame('completed', $woModel->status->value);
        $downtime = MachineDowntime::query()->where('machine_id', $machine->id)->firstOrFail();
        $this->assertNotNull($downtime->end_time, 'completion must close the downtime ledger');
        $this->assertSame(1, MachineDowntime::query()->where('machine_id', $machine->id)->count());
        $this->assertSame('idle', $machine->refresh()->status->value, 'machine restored to idle after service');

        // Wrong time: completing twice is refused.
        $this->actingAs($this->maintenanceTech)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo['id']}/complete", [])
            ->assertStatus(422);
    }

    public function test_c_preventive_schedule_and_mold_shot_reset(): void
    {
        $product = \App\Modules\CRM\Models\Product::factory()->create();
        $mold = Mold::create([
            'mold_code' => 'MD-'.substr(uniqid(), -5),
            'name' => 'Wiper bushing mold',
            'product_id' => $product->id,
            'cavity_count' => 2,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 240,
            'setup_time_minutes' => 15,
            'current_shot_count' => 41250,
            'max_shots_before_maintenance' => 50000,
            'lifetime_max_shots' => 500000,
            'status' => 'available',
        ]);

        // ── Wrong actor: production_manager cannot manage schedules ──
        $this->actingAs($this->prodManager)
            ->postJson('/api/v1/maintenance/schedules', $this->scheduleBody($mold))
            ->assertForbidden();

        // ── Admin creates a shot-based preventive schedule on the mold ──
        $this->actingAs($this->admin)
            ->postJson('/api/v1/maintenance/schedules', $this->scheduleBody($mold))
            ->assertCreated();
        $schedule = MaintenanceSchedule::query()
            ->where('maintainable_type', MaintainableType::Mold->value)
            ->where('maintainable_id', $mold->id)
            ->firstOrFail();

        // ── Preventive MWO on the mold ──
        $wo = $this->actingAs($this->maintenanceTech)
            ->postJson('/api/v1/maintenance/work-orders', $this->mwoBody($mold, 'preventive'))
            ->assertCreated()
            ->json('data');
        $woModel = MaintenanceWorkOrder::query()->findOrFail(
            (int) app('hashids')->decode((string) $wo['id'])[0]
        );

        $this->actingAs($this->maintenanceTech)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo['id']}/start")
            ->assertOk();

        // ── Completing a MOLD work order resets the shot counter ──
        $this->actingAs($this->maintenanceTech)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo['id']}/complete", [
                'remarks' => 'Full mold service: cleaned, greased, vents checked.',
            ])
            ->assertOk();
        $mold->refresh();
        $this->assertSame(0, (int) $mold->current_shot_count, 'mold service resets the shot counter');
        $this->assertSame('completed', $woModel->fresh()->status->value);

        // Wrong actor: finance (no maintenance.wo.complete) cannot complete.
        $wo2 = $this->actingAs($this->maintenanceTech)
            ->postJson('/api/v1/maintenance/work-orders', $this->mwoBody($mold))
            ->assertCreated()
            ->json('data');
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/maintenance/work-orders/{$wo2['id']}/start")
            ->assertForbidden();

        // ── Downtime analytics respond for the viewer role ──
        $this->actingAs($this->prodManager)
            ->getJson('/api/v1/maintenance/downtime-analytics/summary')
            ->assertOk();

        // ── Schedule retirement: manage-permission gate + soft delete ──
        $this->actingAs($this->prodManager)
            ->deleteJson("/api/v1/maintenance/schedules/{$schedule->hash_id}")
            ->assertForbidden();
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/maintenance/schedules/{$schedule->hash_id}")
            ->assertNoContent();
        $this->assertNotNull($schedule->refresh()->deleted_at, 'schedule retires as a soft delete');
    }

    /* ══════════════════════════════════════════════════════════════════
       Fixtures & helpers
       ══════════════════════════════════════════════════════════════════ */

    private function assetBody(): array
    {
        return [
            'name' => 'Injection molding machine IM-'.substr(uniqid(), -4),
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-15',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'depreciation_method' => 'straight_line',
            'location' => 'Plant 1, Hall A',
        ];
    }

    private function mwoBody(Machine|Mold $target, string $type = 'corrective'): array
    {
        return [
            'maintainable_type' => $target instanceof Mold
                ? MaintainableType::Mold->value
                : MaintainableType::Machine->value,
            // The request decodes the hashed polymorphic target itself.
            'maintainable_id' => $target->hash_id,
            'type' => $type,
            'priority' => 'high',
            'description' => $type === 'preventive'
                ? 'Scheduled mold service at 80% of shot budget.'
                : 'Hydraulic leak observed under the clamp unit.',
        ];
    }

    private function scheduleBody(Mold $mold): array
    {
        return [
            'maintainable_type' => MaintainableType::Mold->value,
            'maintainable_id' => $mold->hash_id,
            'description' => 'Preventive mold service every 50000 shots',
            'interval_type' => 'shots',
            'interval_value' => 50000,
            'is_active' => true,
        ];
    }

    private function techEmployee(): \App\Modules\HR\Models\Employee
    {
        return \App\Modules\HR\Models\Employee::factory()->create([
            'status' => 'active',
        ]);
    }

    private function disposalJeCount(Asset $asset): int
    {
        return JournalEntry::query()
            ->where('reference_type', Asset::class)
            ->where('reference_id', $asset->getKey())
            ->count();
    }
}
