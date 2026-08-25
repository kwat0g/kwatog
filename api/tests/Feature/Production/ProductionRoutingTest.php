<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionRoutingService;
use App\Modules\Production\Services\WoOperationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * M052 — product routing definition, version invariant, and authorization.
 *
 * The findings this covers, in order of the action plan:
 *   1. exactly one active version per product, enforced by the partial unique
 *      index and not only by the service
 *   2. an edit publishes a new version, so `wo_operations.routing_operation_id`
 *      for already-generated work keeps pointing at a real definition
 *   3. resource state / sequence / decimal validation reaches direct service
 *      callers, not only HTTP ones
 *   4. a committed routing change records a durable MRP replan
 *   5. every version transition is audited; a superseded version is put back
 *      into service with `activate()`, never edited in place
 *   6. production_manager is view-only on routings; ppc_head authors them
 */
class ProductionRoutingTest extends TestCase
{
    use RefreshDatabase;

    private ProductionRoutingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->service = app(ProductionRoutingService::class);
    }

    // ── Fixtures ─────────────────────────────────────────────────

    private function product(): Product
    {
        return Product::factory()->create();
    }

    private function machine(string $status = 'idle'): Machine
    {
        return Machine::factory()->create(['status' => $status]);
    }

    private function mold(Product $product, string $status = 'available'): Mold
    {
        return Mold::create([
            'mold_code'                    => 'MD-' . substr(uniqid(), -5),
            'name'                         => 'Routing Test Mold',
            'product_id'                   => $product->id,
            'cavity_count'                 => 2,
            'cycle_time_seconds'           => 30,
            'output_rate_per_hour'          => 120,
            'setup_time_minutes'           => 15,
            'current_shot_count'           => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots'           => 1000000,
            'status'                       => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function operation(array $overrides = []): array
    {
        return array_merge([
            'sequence'               => 10,
            'operation_name'         => 'Injection',
            'work_center'            => 'IM-01',
            'machine_id'             => null,
            'mold_id'                => null,
            'setup_time_minutes'     => '15.00',
            'cycle_time_minutes'     => '1.50',
            'labor_rate_per_hour'    => '120.0000',
            'machine_rate_per_hour'  => '250.0000',
            'overhead_rate_per_hour' => '80.0000',
            'description'            => 'Run at 210C.',
            'qc_required'            => false,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function payload(Product $product, array $operations = []): array
    {
        return [
            'product_id' => $product->id,
            'notes'      => 'Baseline process plan.',
            'operations' => $operations === [] ? [$this->operation()] : $operations,
        ];
    }

    private function actor(string ...$permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'M052 ' . uniqid(),
            'slug' => 'm052-' . substr(uniqid(), -8),
        ]);
        $ids = [];
        foreach ($permissionSlugs as $slug) {
            $ids[] = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => ucfirst(str_replace('.', ' ', $slug)), 'module' => 'production'],
            )->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function roleActor(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);
    }

    // ── 1. One active version, audited transitions ───────────────

    public function test_create_publishes_the_only_active_version(): void
    {
        $product = $this->product();

        $first = $this->service->create($this->payload($product));
        $second = $this->service->create($this->payload($product));

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertFalse($first->fresh()->is_active, 'Publishing v2 must supersede v1.');
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame(1, ProductRouting::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->count());
    }

    public function test_both_sides_of_a_version_handover_are_audited(): void
    {
        $product = $this->product();
        $v1 = $this->service->create($this->payload($product));
        $v2 = $this->service->create($this->payload($product));

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => ProductRouting::class,
            'model_id'   => $v2->id,
            'action'     => 'created',
        ]);
        // The displaced version is the half a mass `update()` would have lost.
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => ProductRouting::class,
            'model_id'   => $v1->id,
            'action'     => 'updated',
        ]);
        $this->assertTrue(AuditLog::query()
            ->where('model_type', RoutingOperation::class)
            ->exists(), 'Operation rows are the definition work orders cite; they must be audited too.');
    }

    public function test_the_database_refuses_a_second_active_version(): void
    {
        $product = $this->product();
        $this->service->create($this->payload($product));
        $superseded = ProductRouting::create([
            'product_id'       => $product->id,
            'version'          => 99,
            'is_active'        => false,
            'total_cycle_time' => '1.50',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('product_routings')->where('id', $superseded->id)->update(['is_active' => true]);
    }

    // ── 2. Provenance for already-generated work ─────────────────

    public function test_an_edit_publishes_a_new_version_and_keeps_work_order_provenance(): void
    {
        $product = $this->product();
        $v1 = $this->service->create($this->payload($product));

        $wo = WorkOrder::factory()->create(['product_id' => $product->id]);
        app(WoOperationService::class)->generateFromRouting($wo);
        $woOp = WoOperation::query()->where('work_order_id', $wo->id)->firstOrFail();
        $citedOperationId = $woOp->routing_operation_id;
        $this->assertNotNull($citedOperationId);

        $v2 = $this->service->update($v1, $this->payload($product, [
            $this->operation(['operation_name' => 'Injection (revised)', 'cycle_time_minutes' => '2.25']),
        ]));

        $this->assertSame(2, $v2->version);
        $this->assertNotSame($v1->id, $v2->id, 'An edit must not mutate the version in service.');
        $this->assertSame($citedOperationId, $woOp->fresh()->routing_operation_id);
        $this->assertDatabaseHas('routing_operations', [
            'id'             => $citedOperationId,
            'operation_name' => 'Injection',
        ]);
        $this->assertSame('1.50', (string) RoutingOperation::findOrFail($citedOperationId)->cycle_time_minutes);
    }

    public function test_a_superseded_version_cannot_be_edited_in_place(): void
    {
        $product = $this->product();
        $v1 = $this->service->create($this->payload($product));
        $this->service->create($this->payload($product));

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Only the active routing can be edited.');
        $this->service->update($v1->fresh(), $this->payload($product));
    }

    public function test_a_stale_in_memory_version_cannot_publish_over_a_newer_one(): void
    {
        $product = $this->product();
        $stale = $this->service->create($this->payload($product));
        $this->service->create($this->payload($product));

        // The caller's copy still believes it holds the active version — this
        // is the lost-update window that only a re-read under the lock closes.
        $this->assertTrue($stale->is_active);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Only the active routing can be edited.');
        $this->service->update($stale, $this->payload($product));
    }

    public function test_duplicate_supersedes_the_source_version(): void
    {
        $product = $this->product();
        $v1 = $this->service->create($this->payload($product));

        $v2 = $this->service->duplicate($v1->fresh());

        $this->assertSame(2, $v2->version);
        $this->assertFalse($v1->fresh()->is_active);
        $this->assertTrue($v2->fresh()->is_active);
        $this->assertSame(
            $v1->operations->first()->operation_name,
            $v2->operations->first()->operation_name,
        );
    }

    // ── 3. Definition validation reaches direct callers ──────────

    public function test_duplicate_operation_sequences_are_rejected(): void
    {
        $product = $this->product();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('unique positive sequence');
        $this->service->create($this->payload($product, [
            $this->operation(['sequence' => 10]),
            $this->operation(['sequence' => 10, 'operation_name' => 'Trim']),
        ]));
    }

    public function test_a_mold_belonging_to_another_product_is_rejected(): void
    {
        $product = $this->product();
        $otherMold = $this->mold($this->product());

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('mold configured for another product');
        $this->service->create($this->payload($product, [
            $this->operation(['mold_id' => $otherMold->id]),
        ]));
    }

    public function test_an_unusable_machine_status_is_rejected(): void
    {
        $product = $this->product();
        $machine = $this->machine('maintenance');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('machine that is not usable');
        $this->service->create($this->payload($product, [
            $this->operation(['machine_id' => $machine->id]),
        ]));
    }

    public function test_an_archived_machine_is_rejected(): void
    {
        $product = $this->product();
        $machine = $this->machine();
        $machine->delete();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('missing or archived machine');
        $this->service->create($this->payload($product, [
            $this->operation(['machine_id' => $machine->id]),
        ]));
    }

    public function test_an_incompatible_machine_and_mold_pair_is_rejected(): void
    {
        $product = $this->product();
        $machine = $this->machine();
        $mold = $this->mold($product);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('incompatible machine and mold');
        $this->service->create($this->payload($product, [
            $this->operation(['machine_id' => $machine->id, 'mold_id' => $mold->id]),
        ]));
    }

    public function test_a_compatible_machine_and_mold_pair_is_accepted(): void
    {
        $product = $this->product();
        $machine = $this->machine();
        $mold = $this->mold($product);
        $mold->compatibleMachines()->attach($machine->id);

        $routing = $this->service->create($this->payload($product, [
            $this->operation(['machine_id' => $machine->id, 'mold_id' => $mold->id]),
        ]));

        $this->assertSame($machine->id, (int) $routing->operations->first()->machine_id);
        $this->assertSame($mold->id, (int) $routing->operations->first()->mold_id);
    }

    public function test_an_inactive_product_cannot_receive_a_routing(): void
    {
        $product = $this->product();
        $product->forceFill(['is_active' => false])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('does not exist, is inactive, or is archived');
        $this->service->create($this->payload($product));
    }

    public function test_a_rate_beyond_the_column_scale_is_rejected(): void
    {
        $product = $this->product();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('labor rate supports at most 4 decimal places');
        $this->service->create($this->payload($product, [
            $this->operation(['labor_rate_per_hour' => '120.123456']),
        ]));
    }

    public function test_a_zero_cycle_time_is_rejected(): void
    {
        $product = $this->product();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('positive cycle time');
        $this->service->create($this->payload($product, [
            $this->operation(['cycle_time_minutes' => '0.00']),
        ]));
    }

    public function test_total_cycle_time_is_summed_with_decimal_arithmetic(): void
    {
        $product = $this->product();

        $routing = $this->service->create($this->payload($product, [
            $this->operation(['sequence' => 10, 'cycle_time_minutes' => '0.10']),
            $this->operation(['sequence' => 20, 'cycle_time_minutes' => '0.20']),
        ]));

        $this->assertSame('0.30', (string) $routing->fresh()->total_cycle_time);
    }

    // ── 4. Durable propagation to MRP ────────────────────────────

    public function test_a_published_routing_records_a_durable_mrp_replan(): void
    {
        $product = $this->product();
        $salesOrder = SalesOrder::factory()->create();
        $salesOrder->forceFill(['status' => 'confirmed'])->save();
        SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id'     => $product->id,
        ]);

        $routing = $this->service->create($this->payload($product));

        $this->assertDatabaseHas('event_outbox', [
            'dedupe_key' => 'mrp:replan:routing:' . $routing->id . ':routing_created',
            'event_type' => MrpReplanRequested::class,
        ]);
    }

    public function test_no_replan_is_recorded_when_no_active_sales_order_needs_the_product(): void
    {
        $this->service->create($this->payload($this->product()));

        $this->assertDatabaseCount('event_outbox', 0);
    }

    // ── 5. Rollback lifecycle ────────────────────────────────────

    public function test_activate_puts_a_superseded_version_back_into_service(): void
    {
        $product = $this->product();
        $v1 = $this->service->create($this->payload($product));
        $v2 = $this->service->create($this->payload($product));

        $reactivated = $this->service->activate($v1->fresh());

        $this->assertSame(1, $reactivated->version, 'Rolling back must not renumber history.');
        $this->assertTrue($v1->fresh()->is_active);
        $this->assertFalse($v2->fresh()->is_active);
        $this->assertSame(1, ProductRouting::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->count());
    }

    public function test_activate_is_idempotent_for_the_version_already_in_service(): void
    {
        $product = $this->product();
        $active = $this->service->create($this->payload($product));

        $result = $this->service->activate($active->fresh());

        $this->assertSame($active->id, $result->id);
        $this->assertTrue($result->is_active);
    }

    public function test_activate_refuses_a_version_whose_mold_is_no_longer_usable(): void
    {
        $product = $this->product();
        $machine = $this->machine();
        $mold = $this->mold($product);
        $mold->compatibleMachines()->attach($machine->id);

        $v1 = $this->service->create($this->payload($product, [
            $this->operation(['machine_id' => $machine->id, 'mold_id' => $mold->id]),
        ]));
        $v2 = $this->service->create($this->payload($product));
        $mold->forceFill(['status' => 'retired'])->save();

        try {
            $this->service->activate($v1->fresh());
            $this->fail('A retired mold must block reactivation.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('mold that is not usable', $e->getMessage());
        }

        $this->assertFalse($v1->fresh()->is_active);
        $this->assertTrue($v2->fresh()->is_active, 'A refused rollback must leave the active version alone.');
    }

    // ── 6. Authorization and the role matrix ─────────────────────

    public function test_view_permission_reads_routings_but_cannot_write_them(): void
    {
        $product = $this->product();
        $routing = $this->service->create($this->payload($product));
        $viewer = $this->actor('production.routings.view');

        $this->actingAs($viewer)->getJson('/api/v1/production/routings')->assertOk();
        $this->actingAs($viewer)
            ->getJson("/api/v1/production/routings/{$routing->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $routing->hash_id);

        $writePayload = [
            'product_id' => $product->hash_id,
            'operations' => [$this->operation()],
        ];
        $this->actingAs($viewer)->postJson('/api/v1/production/routings', $writePayload)->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/v1/production/routings/{$routing->hash_id}", $writePayload)->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/production/routings/{$routing->hash_id}/duplicate")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/production/routings/{$routing->hash_id}/activate")->assertForbidden();
    }

    public function test_production_manager_may_read_routings_but_not_author_them(): void
    {
        $product = $this->product();
        $routing = $this->service->create($this->payload($product));
        $manager = $this->roleActor('production_manager');

        $this->assertTrue($manager->hasPermission('production.routings.view'));
        $this->assertFalse(
            $manager->hasPermission('production.routings.manage'),
            'docs/AUTO-BROWSER-TESTS.md §1.3 gives routing authorship to ppc_head.',
        );

        $this->actingAs($manager)->getJson("/api/v1/production/routings/{$routing->hash_id}")->assertOk();
        $this->actingAs($manager)
            ->postJson('/api/v1/production/routings', [
                'product_id' => $product->hash_id,
                'operations' => [$this->operation()],
            ])
            ->assertForbidden();
    }

    public function test_ppc_head_owns_the_routing_write_surface(): void
    {
        $product = $this->product();
        $ppc = $this->roleActor('ppc_head');

        $created = $this->actingAs($ppc)
            ->postJson('/api/v1/production/routings', [
                'product_id' => $product->hash_id,
                'notes'      => 'Authored by PPC.',
                'operations' => [$this->operation()],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1, $created['version']);
        $this->assertTrue($created['is_active']);

        $duplicated = $this->actingAs($ppc)
            ->postJson("/api/v1/production/routings/{$created['id']}/duplicate")
            ->assertCreated()
            ->json('data');
        $this->assertSame(2, $duplicated['version']);

        $this->actingAs($ppc)
            ->postJson("/api/v1/production/routings/{$created['id']}/activate")
            ->assertOk()
            ->assertJsonPath('data.version', 1);
    }

    public function test_the_api_rejects_duplicate_sequences_with_a_field_error(): void
    {
        $product = $this->product();

        $this->actingAs($this->actor('production.routings.manage'))
            ->postJson('/api/v1/production/routings', [
                'product_id' => $product->hash_id,
                'operations' => [
                    $this->operation(['sequence' => 10]),
                    $this->operation(['sequence' => 10]),
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operations.0.sequence', 'operations.1.sequence']);
    }

    public function test_the_api_rejects_a_mold_that_is_not_available(): void
    {
        $product = $this->product();
        $mold = $this->mold($product, 'retired');

        $this->actingAs($this->actor('production.routings.manage'))
            ->postJson('/api/v1/production/routings', [
                'product_id' => $product->hash_id,
                'operations' => [$this->operation(['mold_id' => $mold->hash_id])],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operations.0.mold_id']);
    }

    // ── 8. List filtering ────────────────────────────────────────

    public function test_the_list_filters_by_product_text_and_active_state(): void
    {
        $wanted = Product::factory()->create(['part_number' => 'RT-WANTED-1', 'name' => 'Wiper bushing']);
        $other = Product::factory()->create(['part_number' => 'RT-OTHER-1', 'name' => 'Pivot cap']);

        $this->service->create($this->payload($wanted));   // v1, superseded below
        $this->service->create($this->payload($wanted));   // v2, active
        $this->service->create($this->payload($other));

        $viewer = $this->actor('production.routings.view');

        $bySearch = $this->actingAs($viewer)
            ->getJson('/api/v1/production/routings?search=RT-WANTED')
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $bySearch);

        $byName = $this->actingAs($viewer)
            ->getJson('/api/v1/production/routings?search=Pivot')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $byName);

        $activeOnly = $this->actingAs($viewer)
            ->getJson('/api/v1/production/routings?search=RT-WANTED&is_active=true')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $activeOnly);
        $this->assertSame(2, $activeOnly[0]['version']);

        $supersededOnly = $this->actingAs($viewer)
            ->getJson('/api/v1/production/routings?search=RT-WANTED&is_active=false')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $supersededOnly);
        $this->assertSame(1, $supersededOnly[0]['version']);
    }
}
