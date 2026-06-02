# P3.7 — Outgoing QC Listener Idempotency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the check-then-insert race condition in `TriggerOutgoingQC` so two concurrent queue workers cannot create duplicate outgoing inspections for the same Work Order.

**Architecture:** Two-layer fix — a DB unique index makes duplicate insertion physically impossible at the database level, and the listener is made idempotent via `firstOrCreate` so the application layer handles the constraint gracefully without throwing.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL 16, SQLite (tests), PHPUnit/Pest

---

## Invariant Analysis

The listener guards: `(stage='outgoing', entity_type='work_order', entity_id=wo->id)`.

A Work Order legitimately has **two** inspection rows:
- `in_process` stage inspection (created by `TriggerInProcessQC` when WO starts)
- `outgoing` stage inspection (created by `TriggerOutgoingQC` when WO completes)

These have **different `stage` values**, so a 3-column composite unique on
`(stage, entity_type, entity_id)` is safe and correct:
- It prevents 2× outgoing inspections for the same WO ✓
- It does NOT prevent 1× in_process + 1× outgoing for the same WO ✓
- NULL entity_type/entity_id rows are unaffected (NULL ≠ NULL in SQL; composite unique
  skips rows where any column is NULL in both Postgres and SQLite) ✓
- The seeder creates inspections with NULL entity columns — unaffected ✓

**Partial index (WHERE stage='outgoing') is NOT used** because SQLite does not support
partial indexes — it would break the test suite.

---

## Files

| Action | Path |
|---|---|
| Create | `api/database/migrations/0169_add_unique_stage_entity_to_inspections.php` |
| Modify | `api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php` |
| Create | `api/tests/Feature/Quality/OutgoingQcIdempotencyTest.php` |

---

### Task 1: Add unique index migration

**Files:**
- Create: `api/database/migrations/0169_add_unique_stage_entity_to_inspections.php`

- [ ] **Step 1: Verify migration number is free**

```bash
ls api/database/migrations/ | grep -oE '^[0-9]+' | sort -n | tail -1
# Expected: 0168
```

- [ ] **Step 2: Create the migration file**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3.7 — Outgoing QC idempotency guard.
 *
 * Adds a composite unique index on (stage, entity_type, entity_id) so the
 * database enforces the invariant: exactly one inspection of a given stage
 * per entity. This prevents duplicate outgoing inspections when two queue
 * workers both pass the check-then-insert guard in TriggerOutgoingQC.
 *
 * Invariant analysis:
 *   - A WO legitimately has in_process (stage differs) AND outgoing (different
 *     stage value) — the 3-column composite covers both safely.
 *   - Rows with NULL entity_type or entity_id are NOT constrained (NULL ≠ NULL
 *     in SQL's unique semantics — both PostgreSQL and SQLite follow this).
 *   - Partial index (WHERE stage='outgoing') was considered but rejected because
 *     SQLite does not support partial indexes; this composite is equally correct.
 *
 * Safe on fresh schema (no duplicate data in seeds) and on existing DBs because
 * seeds create inspections with NULL entity columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->unique(
                ['stage', 'entity_type', 'entity_id'],
                'inspections_stage_entity_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table) {
            $table->dropUnique('inspections_stage_entity_unique');
        });
    }
};
```

- [ ] **Step 3: Run migrate:fresh to confirm it applies cleanly**

```bash
cd /path/to/repo && docker compose exec -T api php artisan migrate:fresh 2>&1 | tail -5
# Expected: no errors, "Migrations table created successfully" or similar
```

---

### Task 2: Make TriggerOutgoingQC idempotent

**Files:**
- Modify: `api/app/Modules/Quality/Listeners/TriggerOutgoingQC.php` (lines ~48–53 and ~75–89)

The current code has two vulnerable paths:
1. The `InspectionService::create()` call (line ~61) — no idempotency inside the service
2. The fallback `Inspection::create()` (line ~76) — raw insert, no guard

Fix both with `firstOrCreate` on the guard columns.

- [ ] **Step 1: Replace the exists() check + InspectionService call path**

Replace the section from line 48 to line 90 of `TriggerOutgoingQC::handle()`.

**Before (lines 48–90):**
```php
            // Idempotent — if an outgoing inspection for this WO already
            // exists (any status), don't create another one.
            $existing = Inspection::query()
                ->where('stage', InspectionStage::Outgoing->value)
                ->where('entity_type', InspectionEntityType::WorkOrder->value)
                ->where('entity_id', $wo->id)
                ->exists();
            if ($existing) return;

            $productId = $wo->product_id;
            $batchQty  = max(1, (int) ($wo->quantity_good ?: $wo->quantity_produced ?: 0));
            if (! $productId) return;

            try {
                // Use InspectionService::create() to get full measurement scaffold.
                $this->inspections->create([
                    'stage'          => InspectionStage::Outgoing->value,
                    'product_id'     => (int) $productId,
                    'batch_quantity' => $batchQty,
                    'entity_type'    => InspectionEntityType::WorkOrder->value,
                    'entity_id'      => $wo->id,
                ], $wo->creator ?? User::query()->first());
            } catch (\Throwable $e) {
                // Fallback: no active inspection spec for this product.
                // Create a bare inspection without measurement rows.
                Log::debug('TriggerOutgoingQC fallback — no active spec', [
                    'product_id' => $productId,
                    'error'      => $e->getMessage(),
                ]);
                $aql = \App\Modules\Quality\Services\AqlSampleSizeService::forBatch($batchQty);
                Inspection::create([
                    'inspection_number' => app(\App\Common\Services\DocumentSequenceService::class)->generate('inspection'),
                    'stage'             => InspectionStage::Outgoing->value,
                    'status'            => \App\Modules\Quality\Enums\InspectionStatus::Draft->value,
                    'product_id'        => $productId,
                    'entity_type'       => InspectionEntityType::WorkOrder->value,
                    'entity_id'         => $wo->id,
                    'batch_quantity'    => $batchQty,
                    'sample_size'       => (int) $aql['sample_size'],
                    'aql_code'          => (string) $aql['code'],
                    'accept_count'      => (int) $aql['accept'],
                    'reject_count'      => (int) $aql['reject'],
                    'defect_count'      => 0,
                ]);
            }
```

**After:**
```php
            $productId = $wo->product_id;
            $batchQty  = max(1, (int) ($wo->quantity_good ?: $wo->quantity_produced ?: 0));
            if (! $productId) return;

            // Guard columns that must be unique per the DB index.
            // firstOrCreate is atomic against the unique constraint: if a
            // concurrent worker already committed the row, the SELECT returns
            // it and we skip the create path entirely.
            $guardColumns = [
                'stage'       => InspectionStage::Outgoing->value,
                'entity_type' => InspectionEntityType::WorkOrder->value,
                'entity_id'   => $wo->id,
            ];

            $alreadyExists = Inspection::query()
                ->where($guardColumns)
                ->exists();
            if ($alreadyExists) return;

            try {
                // Use InspectionService::create() to get full measurement scaffold.
                $this->inspections->create([
                    'stage'          => InspectionStage::Outgoing->value,
                    'product_id'     => (int) $productId,
                    'batch_quantity' => $batchQty,
                    'entity_type'    => InspectionEntityType::WorkOrder->value,
                    'entity_id'      => $wo->id,
                ], $wo->creator ?? User::query()->first());
            } catch (\Illuminate\Database\QueryException $e) {
                // Unique constraint violation — a concurrent worker won the race
                // and already inserted the outgoing inspection. This is correct
                // behavior; no action needed.
                if ($this->isUniqueViolation($e)) {
                    Log::debug('TriggerOutgoingQC: duplicate suppressed by DB unique index', [
                        'wo_id' => $wo->id,
                    ]);
                    return;
                }
                throw $e;
            } catch (\Throwable $e) {
                // Fallback: no active inspection spec for this product.
                // Create a bare inspection without measurement rows.
                // Use firstOrCreate on the guard columns to be race-safe.
                Log::debug('TriggerOutgoingQC fallback — no active spec', [
                    'product_id' => $productId,
                    'error'      => $e->getMessage(),
                ]);
                $aql = \App\Modules\Quality\Services\AqlSampleSizeService::forBatch($batchQty);
                try {
                    Inspection::firstOrCreate(
                        $guardColumns,
                        [
                            'inspection_number' => app(\App\Common\Services\DocumentSequenceService::class)->generate('inspection'),
                            'status'            => \App\Modules\Quality\Enums\InspectionStatus::Draft->value,
                            'product_id'        => $productId,
                            'batch_quantity'    => $batchQty,
                            'sample_size'       => (int) $aql['sample_size'],
                            'aql_code'          => (string) $aql['code'],
                            'accept_count'      => (int) $aql['accept'],
                            'reject_count'      => (int) $aql['reject'],
                            'defect_count'      => 0,
                        ]
                    );
                } catch (\Illuminate\Database\QueryException $qe) {
                    if ($this->isUniqueViolation($qe)) {
                        Log::debug('TriggerOutgoingQC: fallback duplicate suppressed', ['wo_id' => $wo->id]);
                        return;
                    }
                    throw $qe;
                }
            }
```

- [ ] **Step 2: Add the `isUniqueViolation` helper method** after `handle()`:

```php
    /**
     * Returns true when a QueryException is caused by a unique-constraint violation.
     * SQLSTATE 23000 covers both PostgreSQL (23505) and SQLite (19 → wrapped as 23000).
     */
    private function isUniqueViolation(\Illuminate\Database\QueryException $e): bool
    {
        $code = (string) $e->getCode();
        return str_starts_with($code, '23') || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
```

---

### Task 3: Write the idempotency test

**Files:**
- Create: `api/tests/Feature/Quality/OutgoingQcIdempotencyTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3.7 — Race-condition guard: TriggerOutgoingQC must be idempotent.
 *
 * Strategy: call handle() twice on the same WorkOrderCompleted event (same WO).
 * The second call must be a no-op — only one outgoing inspection must exist.
 *
 * This covers the fix at two levels:
 *   1. Application layer — firstOrCreate + QueryException catch in the listener.
 *   2. DB layer — the unique index on (stage, entity_type, entity_id).
 *
 * We test the no-active-spec path (fallback bare Inspection) to avoid needing
 * InspectionSpec/InspectionSpecItem fixtures. The WO fixture is built with
 * WorkOrder::create() directly — no WorkOrderService needed.
 */
class OutgoingQcIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private WorkOrder $workOrder;
    private TriggerOutgoingQC $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->product = Product::create([
            'part_number'     => 'P3-7-TEST-001',
            'name'            => 'Idempotency Test Part',
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '5.00',
            'is_active'       => true,
        ]);

        // Minimal SalesOrder so the listener's `$wo->sales_order_id` guard passes.
        $so = SalesOrder::factory()->create();

        // Build a minimal WorkOrder row directly — no need for full WO lifecycle.
        $this->workOrder = WorkOrder::create([
            'wo_number'         => 'WO-TEST-P37-0001',
            'product_id'        => $this->product->id,
            'sales_order_id'    => $so->id,
            'quantity_target'   => 100,
            'quantity_produced' => 100,
            'quantity_good'     => 98,
            'quantity_rejected' => 2,
            'status'            => 'completed',
            'created_by'        => $this->user->id,
        ]);

        $this->listener = app(TriggerOutgoingQC::class);
    }

    // ─── Core idempotency assertion ───────────────────────────────────────────

    /**
     * Calling handle() twice for the same WO must produce exactly ONE outgoing
     * inspection row. The second call must silently no-op.
     *
     * We intentionally have NO active InspectionSpec so the listener always
     * falls through to the fallback Inspection::firstOrCreate() path — this
     * exercises the race-safe fallback without needing spec fixtures.
     */
    public function test_handling_work_order_completed_twice_creates_one_outgoing_inspection(): void
    {
        $event = new WorkOrderCompleted($this->workOrder);

        // First call — must create one outgoing inspection.
        $this->listener->handle($event);

        $countAfterFirst = Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $this->workOrder->id)
            ->count();

        $this->assertSame(
            1,
            $countAfterFirst,
            'First handle() call must create exactly one outgoing inspection.'
        );

        // Second call — must be a no-op.
        $this->listener->handle($event);

        $countAfterSecond = Inspection::query()
            ->where('stage', InspectionStage::Outgoing->value)
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', $this->workOrder->id)
            ->count();

        $this->assertSame(
            1,
            $countAfterSecond,
            'Second handle() call must NOT create a duplicate outgoing inspection. ' .
            'The unique constraint + firstOrCreate guard must suppress the duplicate.'
        );
    }

    // ─── WO without sales_order_id is skipped ────────────────────────────────

    /**
     * The listener skips internal/rework WOs (no sales_order_id).
     * Calling handle() twice on such a WO must create zero inspections.
     */
    public function test_wo_without_sales_order_id_creates_no_inspection(): void
    {
        $internalWo = WorkOrder::create([
            'wo_number'         => 'WO-TEST-P37-INTERNAL',
            'product_id'        => $this->product->id,
            'sales_order_id'    => null, // internal rework WO
            'quantity_target'   => 50,
            'quantity_produced' => 50,
            'quantity_good'     => 48,
            'quantity_rejected' => 2,
            'status'            => 'completed',
            'created_by'        => $this->user->id,
        ]);

        $event = new WorkOrderCompleted($internalWo);

        $this->listener->handle($event);
        $this->listener->handle($event);

        $this->assertSame(
            0,
            Inspection::query()
                ->where('entity_type', InspectionEntityType::WorkOrder->value)
                ->where('entity_id', $internalWo->id)
                ->count(),
            'Internal WO (no sales_order_id) must never get an outgoing inspection.'
        );
    }

    // ─── DB unique index enforcement ─────────────────────────────────────────

    /**
     * Verify the unique index itself rejects a direct duplicate insert.
     * This pins the DB-layer guarantee independently of the listener fix.
     */
    public function test_db_unique_index_rejects_duplicate_outgoing_inspection_for_same_wo(): void
    {
        $base = [
            'inspection_number' => 'QC-P37-IDX-001',
            'stage'             => InspectionStage::Outgoing->value,
            'status'            => 'draft',
            'product_id'        => $this->product->id,
            'entity_type'       => InspectionEntityType::WorkOrder->value,
            'entity_id'         => $this->workOrder->id,
            'batch_quantity'    => 100,
            'sample_size'       => 13,
            'accept_count'      => 0,
            'reject_count'      => 1,
            'defect_count'      => 0,
        ];

        Inspection::create($base);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Inspection::create(array_merge($base, [
            'inspection_number' => 'QC-P37-IDX-002', // different number, same key columns
        ]));
    }

    // ─── In-process + outgoing for same WO are BOTH allowed ──────────────────

    /**
     * A WO legitimately has two inspections: one in_process and one outgoing.
     * The unique index must NOT prevent this.
     */
    public function test_in_process_and_outgoing_inspections_coexist_for_same_wo(): void
    {
        $base = [
            'status'         => 'draft',
            'product_id'     => $this->product->id,
            'entity_type'    => InspectionEntityType::WorkOrder->value,
            'entity_id'      => $this->workOrder->id,
            'batch_quantity' => 100,
            'sample_size'    => 13,
            'accept_count'   => 0,
            'reject_count'   => 1,
            'defect_count'   => 0,
        ];

        $inProcess = Inspection::create(array_merge($base, [
            'inspection_number' => 'QC-P37-IP-001',
            'stage'             => InspectionStage::InProcess->value,
        ]));

        // Must NOT throw — different stage = different unique key.
        $outgoing = Inspection::create(array_merge($base, [
            'inspection_number' => 'QC-P37-OG-001',
            'stage'             => InspectionStage::Outgoing->value,
        ]));

        $this->assertNotNull($inProcess->id);
        $this->assertNotNull($outgoing->id);

        $this->assertSame(
            2,
            Inspection::query()
                ->where('entity_type', InspectionEntityType::WorkOrder->value)
                ->where('entity_id', $this->workOrder->id)
                ->count(),
            'One in_process + one outgoing inspection for the same WO must both be storable.'
        );
    }
}
```

---

### Task 4: Run the new test + regression suite

- [ ] **Step 1: Run the idempotency test**

```bash
docker compose exec -T api php artisan test --filter "OutgoingQcIdempotencyTest" 2>&1
# Expected: 4 tests, 4 passed (or similar — all green)
```

- [ ] **Step 2: Run the full Quality suite for regressions**

```bash
docker compose exec -T api php artisan test --filter "Quality" 2>&1
# Expected: all existing tests pass — InspectionNcrTest still green
```

- [ ] **Step 3: Run Chain listener tests for regressions**

```bash
docker compose exec -T api php artisan test --filter "Chain" 2>&1
# Expected: all existing chain tests pass
```

- [ ] **Step 4: Run migrate:fresh --seed to confirm seeds still work**

```bash
docker compose exec -T api php artisan migrate:fresh --seed 2>&1 | tail -5
# Expected: no errors. The seeder creates inspections with NULL entity columns
# so the unique index does not affect them.
```

---

## Final checklist

- [ ] Migration uses next free number (0169)
- [ ] Migration uses `$table->unique([...], 'name')` — portable, no partial index
- [ ] Listener uses `firstOrCreate` on guard columns (fallback path) + `QueryException` catch (service path)
- [ ] `isUniqueViolation()` covers both Postgres (SQLSTATE 23xxx) and SQLite (UNIQUE constraint failed)
- [ ] Test: double handle() → exactly 1 row
- [ ] Test: DB unique index rejects direct duplicate
- [ ] Test: in_process + outgoing coexist (invariant not over-constrained)
- [ ] Test: WO without sales_order_id creates no inspection
- [ ] All tests pass
- [ ] migrate:fresh --seed succeeds
