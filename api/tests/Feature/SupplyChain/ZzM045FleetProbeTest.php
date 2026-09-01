<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M045 fleet-driver audit probe. Measurement only — deliberately reports
 * rather than asserts where the expected behaviour is an open question.
 * Renamed/removed before release.
 */
class ZzM045FleetProbeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /* ───────────────────────── A. assignment integrity ───────────────────────── */

    public function test_probe_a1_one_driver_two_vehicles_two_deliveries_simultaneously(): void
    {
        $op = $this->operator();
        $driver = $this->driver();
        $v1 = $this->vehicle();
        $v2 = $this->vehicle();
        $d1 = $this->delivery($op);
        $d2 = $this->delivery($op);

        $a1 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d1->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v1->hash_id, 'reason' => 'probe first route assignment',
        ]);
        $a2 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d2->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v2->hash_id, 'reason' => 'probe second route assignment',
        ]);

        // Now walk BOTH to in_transit through the driver surface.
        $l1 = $this->actingAs($driver)->patchJson("/api/v1/driver/deliveries/{$d1->hash_id}/status", ['status' => 'loading']);
        $l2 = $this->actingAs($driver)->patchJson("/api/v1/driver/deliveries/{$d2->hash_id}/status", ['status' => 'loading']);
        $t1 = $this->actingAs($driver)->patchJson("/api/v1/driver/deliveries/{$d1->hash_id}/status", ['status' => 'in_transit']);
        $t2 = $this->actingAs($driver)->patchJson("/api/v1/driver/deliveries/{$d2->hash_id}/status", ['status' => 'in_transit']);

        fwrite(STDERR, sprintf(
            "\n[A1 driver double-book] assign1=%d assign2=%d load1=%d load2=%d transit1=%d transit2=%d | d1=%s d2=%s | driver_id shared=%s\n",
            $a1->status(), $a2->status(), $l1->status(), $l2->status(), $t1->status(), $t2->status(),
            json_encode($d1->fresh()->status), json_encode($d2->fresh()->status),
            var_export($d1->fresh()->driver_id === $d2->fresh()->driver_id, true),
        ));
        $this->assertTrue(true);
    }

    public function test_probe_a2_two_scheduled_deliveries_share_one_vehicle(): void
    {
        $op = $this->operator();
        $dr1 = $this->driver();
        $dr2 = $this->driver();
        $v = $this->vehicle();
        $d1 = $this->delivery($op);
        $d2 = $this->delivery($op);

        $a1 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d1->hash_id}/assignment", [
            'driver_id' => $dr1->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe shared vehicle first',
        ]);
        $a2 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d2->hash_id}/assignment", [
            'driver_id' => $dr2->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe shared vehicle second',
        ]);
        $l1 = $this->actingAs($dr1)->patchJson("/api/v1/driver/deliveries/{$d1->hash_id}/status", ['status' => 'loading']);
        $l2 = $this->actingAs($dr2)->patchJson("/api/v1/driver/deliveries/{$d2->hash_id}/status", ['status' => 'loading']);

        fwrite(STDERR, sprintf(
            "\n[A2 vehicle overbook] assign1=%d assign2=%d load1=%d load2=%d | both hold vehicle=%s | load2 body=%s\n",
            $a1->status(), $a2->status(), $l1->status(), $l2->status(),
            var_export($d1->fresh()->vehicle_id === $v->id && $d2->fresh()->vehicle_id === $v->id, true),
            substr((string) $l2->getContent(), 0, 160),
        ));
        $this->assertTrue(true);
    }

    public function test_probe_a3_assignment_of_archived_vehicle_and_archived_driver(): void
    {
        $op = $this->operator();
        $driver = $this->driver();
        $v = $this->vehicle();
        $v->delete();
        $d = $this->delivery($op);

        $r = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe archived vehicle assign',
        ]);

        // Inactive driver already covered; probe a soft-deleted driver user.
        $driver2 = $this->driver();
        $v2 = $this->vehicle();
        $driver2->delete();
        $d2 = $this->delivery($op);
        $r2 = $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d2->hash_id}/assignment", [
            'driver_id' => $driver2->hash_id, 'vehicle_id' => $v2->hash_id, 'reason' => 'probe archived driver assign',
        ]);

        fwrite(STDERR, sprintf(
            "\n[A3 archived] archived_vehicle=%d body=%s | archived_driver=%d body=%s\n",
            $r->status(), substr((string) $r->getContent(), 0, 200),
            $r2->status(), substr((string) $r2->getContent(), 0, 200),
        ));
        $this->assertTrue(true);
    }

    public function test_probe_a4_driver_options_and_vehicle_options_leak_archived_or_busy(): void
    {
        $op = $this->operator();
        $busy = $this->driver();
        $archived = $this->driver();
        $archived->delete();
        $v = $this->vehicle();
        $d = $this->delivery($op);
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $busy->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe busy driver option leak',
        ]);
        $this->actingAs($busy)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'loading']);

        $opts = $this->actingAs($op)->getJson('/api/v1/supply-chain/deliveries/driver-options');
        $ids = collect($opts->json('data') ?? [])->pluck('id')->all();

        fwrite(STDERR, sprintf(
            "\n[A4 driver-options] status=%d count=%d busy_driver_listed=%s archived_driver_listed=%s\n",
            $opts->status(), count($ids),
            var_export(in_array($busy->hash_id, $ids, true), true),
            var_export(in_array($archived->hash_id, $ids, true), true),
        ));
        $this->assertTrue(true);
    }

    /* ───────────────────── B. vehicle status transition matrix ───────────────────── */

    public function test_probe_b1_vehicle_status_transition_matrix(): void
    {
        $admin = $this->systemAdmin();
        $states = ['available', 'in_use', 'maintenance', 'retired'];
        $rows = [];
        foreach ($states as $from) {
            foreach ($states as $to) {
                $v = $this->vehicle($from);
                $r = $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['status' => $to]);
                $rows[] = sprintf('%-11s -> %-11s = %d (now %s)', $from, $to, $r->status(), (string) $v->fresh()->status);
            }
        }
        fwrite(STDERR, "\n[B1 vehicle transition matrix — 16 cells]\n".implode("\n", $rows)."\n");
        $this->assertTrue(true);
    }

    public function test_probe_b2_retire_vehicle_mid_delivery_and_reactivate(): void
    {
        $op = $this->operator();
        $admin = $this->systemAdmin();
        $driver = $this->driver();
        $v = $this->vehicle();
        $d = $this->delivery($op);
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe retire mid delivery',
        ]);
        $this->actingAs($driver)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'loading']);
        $this->actingAs($driver)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'in_transit']);

        $retire = $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['status' => 'retired']);
        $archive = $this->actingAs($admin)->deleteJson("/api/v1/supply-chain/vehicles/{$v->hash_id}");

        // Now finish the delivery and try again.
        $this->actingAs($driver)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => 'delivered']);
        $retire2 = $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['status' => 'retired']);
        $react = $this->actingAs($admin)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['status' => 'available']);

        fwrite(STDERR, sprintf(
            "\n[B2] retire_mid_transit=%d archive_mid_transit=%d | after_delivered: retire=%d reactivate=%d final=%s\n",
            $retire->status(), $archive->status(), $retire2->status(), $react->status(), (string) $v->fresh()->status,
        ));
        $this->assertTrue(true);
    }

    public function test_probe_b3_archive_vehicle_with_history_then_read_delivery(): void
    {
        $op = $this->operator();
        $admin = $this->systemAdmin();
        $driver = $this->driver();
        $v = $this->vehicle();
        $d = $this->delivery($op);
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe archive with history',
        ]);
        foreach (['loading', 'in_transit', 'delivered'] as $s) {
            $this->actingAs($driver)->patchJson("/api/v1/driver/deliveries/{$d->hash_id}/status", ['status' => $s]);
        }
        $plate = $v->plate_number;
        $archive = $this->actingAs($admin)->deleteJson("/api/v1/supply-chain/vehicles/{$v->hash_id}");

        $show = $this->actingAs($admin)->getJson("/api/v1/supply-chain/deliveries/{$d->hash_id}");
        $list = $this->actingAs($admin)->getJson('/api/v1/supply-chain/deliveries');
        $drvShow = $this->actingAs($driver)->getJson("/api/v1/driver/deliveries/{$d->hash_id}");
        $drvList = $this->actingAs($driver)->getJson('/api/v1/driver/deliveries?status=delivered');

        // Plate re-registration after archive.
        $recreate = $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => $plate, 'name' => 'Replacement unit', 'vehicle_type' => 'van',
        ]);

        fwrite(STDERR, sprintf(
            "\n[B3 archive w/ history] archive=%d | delivery show=%d vehicle=%s | list=%d | driver show=%d vehicle=%s | driver list=%d\n"
            ."   fk_still_set=%s | recreate_same_plate=%d body=%s\n",
            $archive->status(), $show->status(), json_encode($show->json('data.vehicle')),
            $list->status(), $drvShow->status(), json_encode($drvShow->json('data.vehicle')), $drvList->status(),
            var_export($d->fresh()->vehicle_id === $v->id, true),
            $recreate->status(), substr((string) $recreate->getContent(), 0, 180),
        ));
        $this->assertTrue(true);
    }

    /* ─────────────────── C. capacity_kg validation family, one per test ─────────────────── */

    public function test_probe_c1_capacity_fractional_1_999(): void { $this->probeCapacity('1.999'); }

    public function test_probe_c2_capacity_exponent_1e3(): void { $this->probeCapacity('1e3'); }

    public function test_probe_c3_capacity_exponent_1e17(): void { $this->probeCapacity('1e17'); }

    public function test_probe_c4_capacity_exponent_1e20(): void { $this->probeCapacity('1e20'); }

    public function test_probe_c5_capacity_overflows_precision(): void { $this->probeCapacity('100000000'); }

    public function test_probe_c6_capacity_negative(): void { $this->probeCapacity('-1'); }

    public function test_probe_c7_capacity_array_payload(): void { $this->probeCapacity(['a' => 1]); }

    public function test_probe_c8_capacity_nan_string(): void { $this->probeCapacity('abc'); }

    private function probeCapacity(mixed $value): void
    {
        $admin = $this->systemAdmin();
        $r = $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => 'CP-'.substr(uniqid(), -8),
            'name' => 'Capacity probe',
            'vehicle_type' => 'truck',
            'capacity_kg' => $value,
        ]);
        $stored = $r->status() < 300 ? (string) Vehicle::query()->latest('id')->value('capacity_kg') : '-';
        fwrite(STDERR, sprintf(
            "\n[C capacity_kg %-14s] http=%d stored=%s body=%s\n",
            is_array($value) ? 'ARRAY' : (string) $value, $r->status(), $stored, substr((string) $r->getContent(), 0, 130),
        ));
        $this->assertTrue(true);
    }

    /* ─────────────────────────── D. permission reachability ─────────────────────────── */

    public function test_probe_d1_fleet_route_permission_matrix_by_seeded_role(): void
    {
        $slugs = Role::query()->orderBy('slug')->pluck('slug')->all();
        $rows = [];
        foreach ($slugs as $slug) {
            $u = User::factory()->create([
                'role_id' => Role::query()->where('slug', $slug)->value('id'), 'is_active' => true,
            ]);
            $v = $this->vehicle();
            $codes = [
                'GET options' => $this->actingAs($u)->getJson('/api/v1/supply-chain/vehicles/options')->status(),
                'GET list' => $this->actingAs($u)->getJson('/api/v1/supply-chain/vehicles')->status(),
                'POST create' => $this->actingAs($u)->postJson('/api/v1/supply-chain/vehicles', [
                    'plate_number' => 'PM-'.substr(uniqid(), -8), 'name' => 'Perm probe', 'vehicle_type' => 'van',
                ])->status(),
                'PATCH update' => $this->actingAs($u)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}", ['name' => 'Renamed'])->status(),
                'DELETE arch' => $this->actingAs($u)->deleteJson("/api/v1/supply-chain/vehicles/{$v->hash_id}")->status(),
                'PATCH restore' => $this->actingAs($u)->patchJson("/api/v1/supply-chain/vehicles/{$v->hash_id}/restore")->status(),
                'GET drv-opts' => $this->actingAs($u)->getJson('/api/v1/supply-chain/deliveries/driver-options')->status(),
            ];
            $rows[] = sprintf('%-20s %s', $slug, json_encode($codes));
        }
        fwrite(STDERR, "\n[D1 fleet permission matrix by seeded role]\n".implode("\n", $rows)."\n");
        $this->assertTrue(true);
    }

    public function test_probe_d2_who_holds_fleet_manage(): void
    {
        $holders = [];
        foreach (Role::query()->orderBy('slug')->get() as $role) {
            $u = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
            $holders[$role->slug] = [
                'fleet.manage' => $u->hasPermission('supply_chain.fleet.manage'),
                'deliveries.create' => $u->hasPermission('supply_chain.deliveries.create'),
                'deliveries.confirm' => $u->hasPermission('supply_chain.deliveries.confirm'),
                'driver.access' => $u->hasPermission('supply_chain.driver.access'),
                'supply_chain.view' => $u->hasPermission('supply_chain.view'),
            ];
        }
        $lines = [];
        foreach ($holders as $slug => $map) {
            $lines[] = sprintf('%-20s %s', $slug, json_encode(array_map(static fn ($b) => $b ? 'Y' : '.', $map)));
        }
        fwrite(STDERR, "\n[D2 permission holders]\n".implode("\n", $lines)."\n");
        $this->assertTrue(true);
    }

    /* ─────────────────────────── E. immutability / raw sql ─────────────────────────── */

    public function test_probe_e1_vehicle_and_assignment_history_mutability(): void
    {
        $op = $this->operator();
        $driver = $this->driver();
        $v = $this->vehicle();
        $d = $this->delivery($op);
        $this->actingAs($op)->patchJson("/api/v1/supply-chain/deliveries/{$d->hash_id}/assignment", [
            'driver_id' => $driver->hash_id, 'vehicle_id' => $v->hash_id, 'reason' => 'probe immutability baseline',
        ]);

        $notesBefore = (string) $d->fresh()->notes;

        // Eloquent rewrite of the plate (identity of a historical record).
        $eloquent = 'n/a';
        try {
            $v->forceFill(['plate_number' => 'REWRITTEN-1'])->save();
            $eloquent = 'ALLOWED -> '.(string) $v->fresh()->plate_number;
        } catch (\Throwable $e) {
            $eloquent = 'REFUSED: '.substr($e->getMessage(), 0, 90);
        }

        // Raw SQL rewrite of the assignment audit trail (deliveries.notes).
        $rawNotes = 'n/a';
        try {
            DB::statement('update deliveries set notes = ? where id = ?', ['assignment history erased', $d->id]);
            $rawNotes = 'ALLOWED -> '.(string) $d->fresh()->notes;
        } catch (\Throwable $e) {
            $rawNotes = 'REFUSED: '.substr($e->getMessage(), 0, 90);
        }

        // Raw SQL hard delete of the vehicle a delivered delivery points at.
        $rawDelete = 'n/a';
        try {
            DB::statement('delete from vehicles where id = ?', [$v->id]);
            $rawDelete = 'ALLOWED; delivery.vehicle_id now '.var_export($d->fresh()->vehicle_id, true);
        } catch (\Throwable $e) {
            $rawDelete = 'REFUSED: '.substr($e->getMessage(), 0, 90);
        }

        $triggers = DB::select(
            "select tgrelid::regclass::text as tbl, count(*) as n from pg_trigger
             where not tgisinternal and tgrelid::regclass::text in ('vehicles','deliveries','delivery_proofs')
             group by 1 order by 1"
        );

        fwrite(STDERR, sprintf(
            "\n[E1 immutability]\n  notes_before=%s\n  eloquent plate rewrite: %s\n  raw notes rewrite: %s\n  raw vehicle delete: %s\n  pg_trigger: %s\n",
            substr($notesBefore, 0, 60), $eloquent, $rawNotes, $rawDelete, json_encode($triggers),
        ));
        $this->assertTrue(true);
    }

    /* ─────────────────────────── F. hash-id / error body hygiene ─────────────────────────── */

    public function test_probe_f1_error_bodies_and_payload_have_no_raw_ids(): void
    {
        $admin = $this->systemAdmin();
        $op = $this->operator();
        $driver = $this->driver();
        $other = $this->driver();
        $v = $this->vehicle();
        $d = $this->delivery($op, $driver, $v);

        $bad = $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => $v->plate_number, 'name' => 'Dup plate', 'vehicle_type' => 'van',
        ]);
        $notFound = $this->actingAs($admin)->patchJson('/api/v1/supply-chain/vehicles/zzzzzzzz', ['name' => 'x']);
        $foreign = $this->actingAs($other)->getJson("/api/v1/driver/deliveries/{$d->hash_id}");
        $list = $this->actingAs($admin)->getJson('/api/v1/supply-chain/vehicles');

        fwrite(STDERR, sprintf(
            "\n[F1 id hygiene] dup_plate=%d body=%s\n  bad_hash=%d\n  foreign_driver=%d body=%s\n"
            ."  list contains raw pk %d: %s | list contains asset_id key: %s\n",
            $bad->status(), substr((string) $bad->getContent(), 0, 150),
            $notFound->status(),
            $foreign->status(), substr((string) $foreign->getContent(), 0, 150),
            $v->id, var_export(str_contains((string) $list->getContent(), '"id":'.$v->id), true),
            var_export(str_contains((string) $list->getContent(), 'asset_id'), true),
        ));
        $this->assertTrue(true);
    }

    /* ─────────────────────────── G. asset / maintenance seam ─────────────────────────── */

    public function test_probe_g1_asset_and_maintenance_seam(): void
    {
        $admin = $this->systemAdmin();
        $v = $this->vehicle();

        $created = $this->actingAs($admin)->postJson('/api/v1/supply-chain/vehicles', [
            'plate_number' => 'AS-'.substr(uniqid(), -8), 'name' => 'Asset seam probe', 'vehicle_type' => 'truck',
            'asset_id' => 1,
        ]);

        $assetId = DB::table('vehicles')->where('id', $v->id)->value('asset_id');
        $newAssetId = $created->status() < 300
            ? DB::table('vehicles')->latest('id')->value('asset_id')
            : 'n/a';

        $maintainable = DB::table('maintenance_work_orders')
            ->select('maintainable_type')->distinct()->pluck('maintainable_type')->all();

        fwrite(STDERR, sprintf(
            "\n[G1 asset seam] factory vehicle asset_id=%s | create-with-asset_id http=%d resulting asset_id=%s\n"
            ."  resource exposes asset_id=%s | MaintainableType cases=%s | seeded maintainable_type values=%s\n",
            var_export($assetId, true), $created->status(), var_export($newAssetId, true),
            var_export(str_contains((string) $created->getContent(), 'asset_id'), true),
            json_encode(\App\Modules\Maintenance\Enums\MaintainableType::values()),
            json_encode($maintainable),
        ));
        $this->assertTrue(true);
    }

    /* ─────────────────────────── H. list filter / ordering hygiene ─────────────────────────── */

    public function test_probe_h1_vehicle_list_filters(): void
    {
        $admin = $this->systemAdmin();
        $this->vehicle();
        $probes = [
            'status=garbage' => '/api/v1/supply-chain/vehicles?status=garbage',
            'per_page=-1' => '/api/v1/supply-chain/vehicles?per_page=-1',
            'per_page=0' => '/api/v1/supply-chain/vehicles?per_page=0',
            'per_page=99999' => '/api/v1/supply-chain/vehicles?per_page=99999',
            'search=%' => '/api/v1/supply-chain/vehicles?search=%25',
            'search=_' => '/api/v1/supply-chain/vehicles?search=_',
            'trashed=bogus' => '/api/v1/supply-chain/vehicles?trashed=bogus',
        ];
        $rows = [];
        foreach ($probes as $label => $url) {
            $r = $this->actingAs($admin)->getJson($url);
            $rows[] = sprintf('%-16s http=%d total=%s per_page=%s', $label, $r->status(),
                var_export($r->json('meta.total'), true), var_export($r->json('meta.per_page'), true));
        }
        fwrite(STDERR, "\n[H1 list filters]\n".implode("\n", $rows)."\n");
        $this->assertTrue(true);
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    private function operator(): User
    {
        $role = Role::create([
            'name' => 'M045 operator '.uniqid(),
            'slug' => 'm045_op_'.substr(uniqid(), -8),
            'description' => 'probe operator',
        ]);
        $ids = Permission::query()
            ->whereIn('slug', ['supply_chain.deliveries.create', 'supply_chain.view', 'supply_chain.deliveries.view'])
            ->pluck('id')->all();
        $role->permissions()->syncWithoutDetaching($ids);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function systemAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }

    private function driver(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'driver')->value('id'),
            'is_active' => true,
        ]);
    }

    private function vehicle(string $status = 'available'): Vehicle
    {
        return Vehicle::create([
            'plate_number' => 'M45-'.substr(uniqid(), -8),
            'name' => 'Probe vehicle '.uniqid(),
            'vehicle_type' => 'van',
            'capacity_kg' => '1000.00',
            'status' => $status,
        ]);
    }

    private function delivery(User $creator, ?User $driver = null, ?Vehicle $vehicle = null): Delivery
    {
        $customer = Customer::create(['name' => 'M045 customer '.uniqid(), 'is_active' => true]);
        $order = SalesOrder::create([
            'so_number' => 'SO-M45-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '12.00',
            'total_amount' => '112.00',
            'status' => 'confirmed',
            'created_by' => $creator->id,
        ]);

        return Delivery::create([
            'delivery_number' => 'DLV-M45-'.substr(uniqid(), -6),
            'sales_order_id' => $order->id,
            'driver_id' => $driver?->id,
            'vehicle_id' => $vehicle?->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
            'created_by' => $creator->id,
        ]);
    }
}
