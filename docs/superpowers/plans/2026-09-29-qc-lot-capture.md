# QC Lot Capture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make QC data capture cost scale with inspection effort instead of lot size — outgoing and in-process inspections adopt the existing `lot_checklist` mode, the defect count gets one implementation shared by the verdict and the CoC evidence check, incoming receipts gain a per-GRN review screen, the incoming checklist can no longer resolve to zero rows, and the GRN form collapses its per-line lot details.

**Architecture:** `inspection_mode` is already stored per inspection and already branch-rendered in the SPA, so this is a mode change plus a shared scaffolding helper, not a new subsystem. `InspectionService::scaffoldLotChecklist()` becomes the one place that turns parameters into checklist rows (no tolerance) and piece rows (tolerance, `sample_index` 1..`measured_pieces`). `App\Modules\Quality\Support\LotDefectCounter` becomes the one place the lot verdict arithmetic lives, called by both `InspectionService::complete()` and `CoCService::assertEvidenceSupportsCertificate()`. The SPA reuses the existing `IncomingLotChecklist` panel (renamed `LotResultPanel`) and the existing per-inspection `lot-result` endpoint; no new backend recording endpoint is introduced.

**Tech Stack:** Laravel 11 / PHP 8.3 / PostgreSQL 16 / PHPUnit feature + unit tests · React 18 + TypeScript + Vite + TanStack Query + vitest · Docker Compose.

**Spec:** `docs/superpowers/specs/2026-09-29-qc-lot-capture-design.md`

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file.
- Enums for all status/type fields — never a bare string column.
- API Resources return `hash_id`, never a raw integer id.
- Money is `decimal(15,2)` — never float. Non-money decimals arrive in the SPA as strings.
- All business logic lives in the Service, wrapped in `DB::transaction()` when it touches money.
- Controllers stay thin; authorization lives in the FormRequest's `authorize()`.
- Every SPA list page handles 5 states: loading (skeleton), error (retry), empty, data, stale.
- Every SPA page is `React.lazy()`-loaded and wrapped in `AuthGuard` + `ModuleGuard` + `PermissionGuard`.
- Numbers use `font-mono tabular-nums`. Never hardcode a colour — tokens only (`spa/src/styles/tokens.css`).
- Never use Bearer tokens; HTTP-only cookies only. Never store auth in localStorage/sessionStorage.
- Commit after each task: `feat: task N — <description>` / `refactor: task N — <description>` / `fix: task N — <description>`.

Task order matters once. **Task 5 (the certificate guard) must land before Task 6 (the mode flip)**: Task 6 makes outgoing lots recordable as counting samples, and only Task 5 stops the certificate guard from refusing those lots with `COC_EVIDENCE_SHORT_OF_SAMPLE`. Task 5 is inert until Task 6 — nothing creates a `lot_checklist` outgoing inspection yet — so it lands first and green on its own.

## Environment facts (verified against the working tree, not assumed)

- `settings` columns (migration `0013`, plus `label`/`description` added by `0198`): `key`, `value` (json), `group`, `label`, `description`, `created_at`, `updated_at`.
- `SettingsService::get(string $key, mixed $default = null): mixed`; also `requiredInt(string $key, ?int $minimum, ?int $maximum): int`.
- `UpdateSettingRequest` uses a per-key map of shape `'key' => ['value' => [...rules]]`; line 394 already holds `'quality.incoming.measured_pieces' => ['value' => ['required', 'integer', 'min:1', 'max:1000']]`.
- `IncomingLotChecklistTest` (namespace `Tests\Feature\Quality`) already imports `ItemQualityPlan`, and provides `$this->user`, `$this->checker`, `$this->item`, `$this->grnSvc`, `$this->inspSvc` and `private function createGrnWith(Item $item, int $quantity = 1000): GoodsReceiptNote`.
- `CoCEvidenceIntegrityTest` provides `private function passedOutgoingThroughService(int $good = 10): Inspection`, `private function fabricatePassedOutgoing(int $batch, int $sample): Inspection`, `private function fabricateMeasurement(Inspection $inspection, int $sampleIndex, ?string $measured, ?bool $isPass): InspectionMeasurement`, and `private function assertCertificateRefused(Inspection $inspection, string $expectedCode): void`. `fabricatePassedOutgoing()` omits `inspection_mode`, so its rows land on the column default `per_unit` — that is deliberate and must stay.
- `CoCService` already imports `InspectionMeasurement`; it does not import `InspectionMode`.
- SPA: `Panel` takes `title`/`meta`/`actions`/`children`; `Checkbox` takes `id`/`checked`/`onChange`/`label`; `Input` takes `label`/`helper`/`error`/`containerClassName`/`fieldSize`; `Button` takes `variant`/`size`/`loading`/`icon`/`iconOnly`.
- SPA icons `LuArrowLeft`, `LuCheck`, `LuChevronDown`, `LuChevronRight` all exist in `@/lib/icons`.
- `IncomingLotChecklist.tsx` already derives `checklistMeasurements` (line 49) and `numericMeasurements` (line 57).
- `detail.tsx` imports the panel at line 36 and renders it at lines 399-400; its other mode gates are at lines 294 and 620.
- `grn/create.tsx` opens the secondary lot-detail row at line 320 (`<tr className="border-b border-subtle bg-subtle/40">`) and closes it at line 410 before `</Fragment>`. `grn/detail.tsx` renders the "Retry incoming QC" button inside the `incomingQcNeedsAttention` block at line 336 and already has `navigate` in scope.

## Test environment

- PHP's default 128M memory limit OOMs the full suite. Bump once per container:
  ```
  docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"
  ```
- Test seed strings: column varchars are mostly 20 chars. Use `'XX-T-'.substr(uniqid(), -5)` (10 chars). Never `'XX-TEST-'.uniqid()` (21 chars → truncation).
- User + role in tests: `User::factory()->create(['role_id' => Role::query()->where('slug', X)->value('id')])`. Never `assignRole()`.
- **Two suites cannot share `ogami_test`.** `RefreshDatabase` runs `migrate:fresh`, so a second suite tears the schema down under the first — hundreds of failures with ZERO assertion failures among them, which is the tell. If another suite may be running, use a private database:
  ```
  docker compose exec -T db psql -U ogami -d postgres -c "CREATE DATABASE ogami_test_verify OWNER ogami;"
  docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test --filter='<pattern>'
  ```
- SPA verification for every frontend task: `cd spa && npx tsc --noEmit && npx vitest run <path>`.

## Migration numbering

The one migration in this plan inserts into `settings`, which no `2026_*` migration creates or alters, so a numbered prefix is correct. `0564` was verified free; the highest existing prefix is `0563`. Re-confirm before writing:

```bash
ls api/database/migrations | grep '^0564_'   # expect no output
```

## Review Focus

Inputs and conditions the spec implies but whose tests are easy to get wrong. Each one is pinned by a test in the task that owns the code.

1. **`is_pass = null` must not count as a defect.** Laravel's `Collection::where('is_pass', false)` uses loose comparison, and `null == false` is `true` in PHP — a naive reimplementation silently counts part-inspected rows as failures. Pinned in Task 1.
2. **A spec with no toleranced parameter produces zero piece rows.** Requiring "at least one measured value" for a certificate would make CoCs permanently unissuable for an all-visual spec. Pinned in Task 5 and Task 6.
3. **A spec where every parameter is toleranced produces zero checklist rows.** The capture panel then has no section 1 and the defect count is the only input. Pinned in Task 6 and Task 7.
4. **"Submit all" must not stop at the first failure.** One row refused by the server must leave the other rows attempted and must show that row's server message. Pinned in Task 9.
5. **A stale lot-review list can hold an inspection that is already `awaiting_review` or terminal.** Submitting it must surface the server's refusal, not report success. Pinned in Task 9.

**Out of scope, recorded deliberately (do not fix here, do not silently ignore):** `GrnService::fastCompleteInspection()` writes `sample_defect_count = 0` on every `lot_checklist` inspection it completes, with the comment "no defects found", on the strength of the operator's single GRN-level verdict rather than any count the operator made. That is the same unattested zero the capture panel explicitly refuses to produce. It is left alone because it belongs to the receive-time single-screen flow and changing it would alter GRN acceptance semantics; raise it as its own issue.

---

### Task 1: `LotDefectCounter` — one implementation of the lot verdict arithmetic

**Files:**
- Create: `api/app/Modules/Quality/Support/LotDefectCounter.php`
- Test: `api/tests/Unit/Quality/LotDefectCounterTest.php`

**Interfaces:**
- Consumes: `App\Modules\Quality\Models\Inspection`, `App\Modules\Quality\Models\InspectionMeasurement`, `App\Modules\Quality\Enums\InspectionMode`.
- Produces: `LotDefectCounter::for(Inspection $inspection, Collection $rows): array{defects: int, criticalFail: bool}` — used by Task 2 (`InspectionService::complete()`) and Task 5 (`CoCService`).

- [ ] **Step 1: Write the failing test**

Create `api/tests/Unit/Quality/LotDefectCounterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Quality;

use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Support\LotDefectCounter;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The lot verdict arithmetic, pinned once. `complete()` and the CoC evidence
 * guard both derived this separately; a divergence between them let a lot pass
 * inspection and then be refused a certificate.
 */
class LotDefectCounterTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: Inspection, 1: Collection<int, InspectionMeasurement>}
     */
    private function inspection(string $mode, ?int $reportedDefects, array $rows): array
    {
        $inspection = (new Inspection)->forceFill([
            'inspection_mode' => $mode,
            'sample_defect_count' => $reportedDefects,
        ]);

        $measurements = new Collection;
        foreach ($rows as $index => $row) {
            $measurements->push((new InspectionMeasurement)->forceFill(array_merge([
                'id' => $index + 1,
                'inspection_id' => 1,
                'sample_index' => $index + 1,
                'parameter_name' => 'Shaft OD',
                'is_pass' => null,
                'is_critical' => false,
                'tolerance_min' => null,
                'tolerance_max' => null,
            ], $row)));
        }

        return [$inspection, $measurements];
    }

    public function test_reported_defect_count_is_used_when_no_piece_failed(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 2, [
            ['is_pass' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
            ['is_pass' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(2, $counted['defects']);
        $this->assertFalse($counted['criticalFail']);
    }

    public function test_failed_pieces_win_when_they_exceed_the_reported_count(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 1, [
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 1],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 2],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 3],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(3, $counted['defects']);
    }

    /**
     * Regression pin: `Collection::where('is_pass', false)` matches null rows
     * because `null == false` is true in PHP. An unresolved row is not a defect.
     */
    public function test_unresolved_rows_are_not_defects(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 0, [
            ['is_pass' => null, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 1],
            ['is_pass' => null, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 2],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(0, $counted['defects']);
    }

    public function test_a_failed_checklist_row_is_not_a_piece_defect(): void
    {
        // Checklist rows (no tolerance) carry the lot-level verdict; only piece
        // rows contribute to the AQL defect count.
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 0, [
            ['is_pass' => false, 'sample_index' => 1],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(0, $counted['defects']);
    }

    public function test_a_failed_critical_row_anywhere_raises_the_critical_flag(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, 0, [
            ['is_pass' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
            ['is_pass' => false, 'is_critical' => true, 'sample_index' => 1],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertTrue($counted['criticalFail']);
    }

    public function test_per_unit_mode_counts_distinct_failing_pieces(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::PerUnit->value, null, [
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 2],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 2],
            ['is_pass' => false, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1', 'sample_index' => 4],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(2, $counted['defects']);
    }

    public function test_a_null_sample_defect_count_reads_as_zero(): void
    {
        [$inspection, $rows] = $this->inspection(InspectionMode::LotChecklist->value, null, [
            ['is_pass' => true, 'tolerance_min' => '9.9', 'tolerance_max' => '10.1'],
        ]);

        $counted = LotDefectCounter::for($inspection, $rows);

        $this->assertSame(0, $counted['defects']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker compose exec -T api php artisan test --filter='LotDefectCounterTest'
```
Expected: FAIL — `Class "App\Modules\Quality\Support\LotDefectCounter" not found`.

- [ ] **Step 3: Write the implementation**

Create `api/app/Modules/Quality/Support/LotDefectCounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Support;

use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Support\Collection;

/**
 * The one implementation of the lot verdict arithmetic.
 *
 * A pass is `! criticalFail && defects <= accept_count`. Both the completion
 * path (InspectionService::complete) and the certificate evidence guard
 * (CoCService::assertEvidenceSupportsCertificate) read the count from here, so
 * a lot cannot pass inspection on one formula and be refused a certificate on
 * another.
 *
 * Every comparison against is_pass is strict (`=== false`). Loose comparison
 * is a trap here: `Collection::where('is_pass', false)` matches rows whose
 * is_pass is null, because null == false is true in PHP, and an unresolved
 * row would then be counted as a defect.
 */
final class LotDefectCounter
{
    /**
     * @param  Collection<int, InspectionMeasurement>  $rows
     * @return array{defects: int, criticalFail: bool}
     */
    public static function for(Inspection $inspection, Collection $rows): array
    {
        $criticalFail = $rows->contains(
            static fn (InspectionMeasurement $row): bool => (bool) $row->is_critical && $row->is_pass === false
        );

        $failed = static fn (InspectionMeasurement $row): bool => $row->is_pass === false;
        $hasTolerance = static fn (InspectionMeasurement $row): bool => $row->tolerance_min !== null || $row->tolerance_max !== null;

        if ($inspection->inspection_mode === InspectionMode::LotChecklist) {
            // Reported defects cover the whole AQL sample the inspector counted;
            // piece rows are the few dimensions actually measured. The larger of
            // the two is the lot's defect count.
            $reported = (int) ($inspection->sample_defect_count ?? 0);
            $failedPieces = $rows->filter($failed)->filter($hasTolerance)
                ->pluck('sample_index')->unique()->count();

            return ['defects' => max($reported, $failedPieces), 'criticalFail' => $criticalFail];
        }

        return [
            'defects' => $rows->filter($failed)->pluck('sample_index')->unique()->count(),
            'criticalFail' => $criticalFail,
        ];
    }
}
```

The comparison against `InspectionMode::LotChecklist` is a direct enum comparison because `Inspection` casts `inspection_mode` to the enum; no `instanceof` dance is needed.

- [ ] **Step 4: Run the test to verify it passes**

```bash
docker compose exec -T api php artisan test --filter='LotDefectCounterTest'
```
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Quality/Support/LotDefectCounter.php api/tests/Unit/Quality/LotDefectCounterTest.php
git commit -m "feat: task 1 — add LotDefectCounter as the one lot verdict implementation"
```

---

### Task 2: `complete()` reads the count from `LotDefectCounter`

**Files:**
- Modify: `api/app/Modules/Quality/Services/InspectionService.php` (the `inspection_mode` branch inside `complete()`, around lines 826-856)
- Test: existing suites only — this is a behaviour-preserving refactor.

**Interfaces:**
- Consumes: `LotDefectCounter::for()` from Task 1.
- Produces: no signature change. `complete()` keeps its exact behaviour and its exact error messages.

A refactor whose behaviour is unchanged should not need a new test file; Tasks 5 and 6 add the behavioural coverage.

- [ ] **Step 1: Record the current behaviour as the baseline**

```bash
docker compose exec -T api php artisan test --filter='IncomingLotChecklistTest|QualitySamplingBoundsTest|InspectionMakerCheckerPolicyTest|InspectionLifecycleConcurrencyTest'
```
Expected: all PASS. Note the count — it must be unchanged at Step 5.

- [ ] **Step 2: Read the block you are replacing**

```bash
sed -n '780,880p' api/app/Modules/Quality/Services/InspectionService.php
```
Identify the `$rows->isEmpty()` check, the `$unresolved > 0` check, and the `if ($lockedInspection->inspection_mode === InspectionMode::LotChecklist) { ... } else { ... }` block computing `$reportedDefects`, `$failedPieces`, `$sampledUnits` and `$declaredSample`. The two precondition checks stay; the branch is replaced.

- [ ] **Step 3: Replace the inline arithmetic**

Replace the whole `inspection_mode` branch (from the `LotChecklist` comparison through the end of the `else` block) with:

```php
            // A lot-checklist verdict is meaningless without the reported count,
            // so the precondition is checked before anything derives from it.
            if ($lockedInspection->inspection_mode === InspectionMode::LotChecklist
                && $lockedInspection->sample_defect_count === null) {
                throw new BusinessRuleException(
                    'Enter the number of defective pieces found in the sample (0 if none).'
                );
            }

            // Per-unit inspections are enumerated, so the declared sample must
            // actually have been measured. Lot-checklist inspections count their
            // sample instead, and their measured pieces are deliberately fewer.
            if ($lockedInspection->inspection_mode !== InspectionMode::LotChecklist) {
                $sampledUnits = $rows->pluck('sample_index')->unique()->count();
                $declaredSample = (int) $lockedInspection->sample_size;
                if ($declaredSample > 0 && $sampledUnits < $declaredSample) {
                    throw new BusinessRuleException(
                        "Cannot complete: inspection declares a sample of {$declaredSample} unit(s) but only {$sampledUnits} were measured.",
                    );
                }
            }

            ['defects' => $defects, 'criticalFail' => $criticalFail] = LotDefectCounter::for($lockedInspection, $rows);
```

Add the import beside the other `App\Modules\Quality\...` imports:

```php
use App\Modules\Quality\Support\LotDefectCounter;
```

Leave the `$rows->isEmpty()` and `$unresolved > 0` checks untouched — they are what guarantee `LotDefectCounter` never sees an unresolved row on this path.

- [ ] **Step 4: Confirm nothing reads the removed locals**

```bash
grep -n "reportedDefects\|failedPieces\|sampledUnits" api/app/Modules/Quality/Services/InspectionService.php
```
Expected: exactly one hit — the `$sampledUnits = ...` line you just added inside the `per_unit` guard.

- [ ] **Step 5: Run the tests to verify they still pass**

```bash
docker compose exec -T api php artisan test --filter='IncomingLotChecklistTest|QualitySamplingBoundsTest|InspectionMakerCheckerPolicyTest|InspectionLifecycleConcurrencyTest'
```
Expected: PASS, same count as Step 1.

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Quality/Services/InspectionService.php
git commit -m "refactor: task 2 — complete() derives its defect count from LotDefectCounter"
```

---

### Task 3: The measured-piece setting, with a single reader

**Files:**
- Create: `api/database/migrations/0564_seed_quality_inspection_measured_pieces.php`
- Modify: `api/app/Modules/Quality/Services/InspectionService.php` (add `measuredPieces()`; `createIncomingFromPlan()` stops calling `requiredInt('quality.incoming.measured_pieces', …)`)
- Modify: `api/app/Modules/Admin/Requests/UpdateSettingRequest.php:394`
- Test: `api/tests/Feature/Quality/IncomingLotChecklistTest.php` (extend)

**Interfaces:**
- Produces: `InspectionService::measuredPieces(): int` (private) — the only reader of either `quality.inspection.measured_pieces` or its predecessor `quality.incoming.measured_pieces`. Used by Tasks 4 and 6.

- [ ] **Step 1: Confirm the migration prefix is free**

```bash
ls api/database/migrations | grep '^0564_'
```
Expected: no output. If a file is already there, take the next free prefix and use it in place of `0564` throughout this task.

- [ ] **Step 2: Write the failing tests**

Append to `api/tests/Feature/Quality/IncomingLotChecklistTest.php`, inside the class:

```php
    public function test_measured_pieces_comes_from_the_new_stage_agnostic_setting(): void
    {
        $this->setSetting('quality.inspection.measured_pieces', 3);
        $this->setSetting('quality.incoming.measured_pieces', 5);

        $inspection = $this->inspectionWithOneTolerancedParameter();

        $this->assertSame(
            3,
            $inspection->measurements->whereNotNull('tolerance_min')->count(),
            'The stage-agnostic setting must win over the legacy incoming-only key.',
        );
    }

    public function test_measured_pieces_falls_back_to_the_legacy_incoming_key(): void
    {
        $this->setSetting('quality.inspection.measured_pieces', null);
        $this->setSetting('quality.incoming.measured_pieces', 2);

        $inspection = $this->inspectionWithOneTolerancedParameter();

        $this->assertSame(2, $inspection->measurements->whereNotNull('tolerance_min')->count());
    }

    public function test_measured_pieces_falls_back_to_five_when_neither_key_is_set(): void
    {
        $this->setSetting('quality.inspection.measured_pieces', null);
        $this->setSetting('quality.incoming.measured_pieces', null);

        $inspection = $this->inspectionWithOneTolerancedParameter();

        $this->assertSame(5, $inspection->measurements->whereNotNull('tolerance_min')->count());
    }
```

Add these two helpers to the same class:

```php
    /** Writes a settings row directly; null deletes it. */
    private function setSetting(string $key, ?int $value): void
    {
        \Illuminate\Support\Facades\DB::table('settings')->where('key', $key)->delete();

        if ($value !== null) {
            \Illuminate\Support\Facades\DB::table('settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'quality',
                'label' => $key,
                'description' => 'Test override.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        \Illuminate\Support\Facades\Cache::flush();
    }

    /**
     * A plan with a single toleranced parameter, so the piece-row count is
     * exactly the measured-piece setting.
     */
    private function inspectionWithOneTolerancedParameter(): Inspection
    {
        $plan = ItemQualityPlan::query()->create([
            'item_id' => $this->item->id,
            'vendor_id' => null,
            'version' => 1,
            'stage' => 'incoming',
            'sampling_method' => 'aql',
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'created_by' => $this->user->id,
            'parameters' => [
                [
                    'parameter_name' => 'Diameter',
                    'parameter_type' => 'dimensional',
                    'unit_of_measure' => 'mm',
                    'nominal_value' => '10.00',
                    'tolerance_min' => '9.90',
                    'tolerance_max' => '10.10',
                    'is_critical' => true,
                ],
            ],
        ]);

        $grn = $this->createGrnWith($this->item, 100);
        $grnItem = $grn->items()->first();
        Inspection::query()->where('grn_item_id', $grnItem->id)->delete();

        return $this->inspSvc->createIncomingFromPlan($plan, $grnItem, $grn, $this->user);
    }
```

A 100-piece line lands on AQL code F (`n = 20`), so any setting in 1..20 is the piece-row count and the assertion is unambiguous.

- [ ] **Step 3: Run the tests to verify they fail**

```bash
docker compose exec -T api php artisan test --filter='measured_pieces'
```
Expected: FAIL — `test_measured_pieces_comes_from_the_new_stage_agnostic_setting` sees 5 rows, not 3, because `createIncomingFromPlan()` still reads the legacy key.

- [ ] **Step 4: Write the implementation**

Create `api/database/migrations/0564_seed_quality_inspection_measured_pieces.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pieces measured per toleranced parameter in a lot-checklist inspection.
 *
 * Stage-agnostic: incoming, in-process and outgoing all seed this many piece
 * rows. The AQL sample size is unaffected — it stays the declared count of
 * pieces inspected visually for defectives, which is why the two numbers are
 * separate settings. `quality.incoming.measured_pieces` is the predecessor and
 * is read as a fallback for one release.
 */
return new class extends Migration {
    private const KEY = 'quality.inspection.measured_pieces';

    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => self::KEY,
            'value' => json_encode(5),
            'group' => 'quality',
            'label' => 'Inspection Measured Pieces',
            'description' => 'Pieces measured per toleranced parameter in lot-checklist inspections, for every stage.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
```

In `api/app/Modules/Quality/Services/InspectionService.php`, replace the line

```php
            $measuredPieces = $this->settings->requiredInt('quality.incoming.measured_pieces', 1, 1000);
```

with

```php
            $measuredPieces = $this->measuredPieces();
```

and add the resolver beside `boundedFullSampleSize()`:

```php
    /**
     * Pieces measured per toleranced parameter in a lot-checklist inspection.
     *
     * The single reader of both keys: the stage-agnostic setting, falling back
     * to its incoming-only predecessor for one release. Resolving here rather
     * than at each call site keeps the two keys from being read inconsistently.
     */
    private function measuredPieces(): int
    {
        $value = $this->settings->get('quality.inspection.measured_pieces')
            ?? $this->settings->get('quality.incoming.measured_pieces');

        if ($value === null) {
            return 5;
        }

        if (! is_numeric($value) || (int) $value != (float) $value) {
            throw new BusinessRuleException('Required setting quality.inspection.measured_pieces is missing or invalid.');
        }

        $pieces = (int) $value;
        if ($pieces < 1 || $pieces > 1000) {
            throw new BusinessRuleException('Required setting quality.inspection.measured_pieces is outside its valid range.');
        }

        return $pieces;
    }
```

In `api/app/Modules/Admin/Requests/UpdateSettingRequest.php`, immediately after the `'quality.incoming.measured_pieces' => ...` line (line 394), add:

```php
            'quality.inspection.measured_pieces' => ['value' => ['required', 'integer', 'min:1', 'max:1000']],
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
docker compose exec -T api php artisan test --filter='measured_pieces|IncomingLotChecklistTest'
```
Expected: PASS. The first filter matches the three new tests; the second confirms the extraction did not disturb the existing plan-path tests.

- [ ] **Step 6: Commit**

```bash
git add api/database/migrations/0564_seed_quality_inspection_measured_pieces.php api/app/Modules/Quality/Services/InspectionService.php api/app/Modules/Admin/Requests/UpdateSettingRequest.php api/tests/Feature/Quality/IncomingLotChecklistTest.php
git commit -m "feat: task 3 — stage-agnostic measured-piece setting with one reader"
```

---

### Task 4: Extract `scaffoldLotChecklist()`

**Files:**
- Modify: `api/app/Modules/Quality/Services/InspectionService.php` (the row-building loop inside `createIncomingFromPlan()`, around lines 284-332)
- Test: existing suites only — behaviour-preserving extraction.

**Interfaces:**
- Consumes: `InspectionService::measuredPieces()` from Task 3.
- Produces: `InspectionService::scaffoldLotChecklist(Inspection $inspection, iterable $parameters, int $measuredPieces): void` (private). Each `$parameter` is an array with keys `parameter_name` (string; blank entries are skipped), `parameter_type` (string), `unit_of_measure` (?string), `nominal_value` (mixed), `tolerance_min` (mixed), `tolerance_max` (mixed), `is_critical` (bool), `notes` (?string), `inspection_spec_item_id` (?int). Task 6 calls it for the spec path.

- [ ] **Step 1: Record the current behaviour as the baseline**

```bash
docker compose exec -T api php artisan test --filter='IncomingLotChecklistTest|ItemQualityPlanTest|IncomingQcTriggerTest|QualityPlanRolloutCommandTest'
```
Expected: all PASS. This count must be unchanged at Step 4.

- [ ] **Step 2: Add the extracted method**

Add to `api/app/Modules/Quality/Services/InspectionService.php`, beside `insertScaffoldRows()`:

```php
    /**
     * Scaffold a lot-checklist inspection.
     *
     * One checklist row (sample_index = 1, no tolerance bounds) for every
     * parameter with no tolerance band, and `$measuredPieces` piece rows
     * (sample_index 1..N, with bounds) for every parameter that has one. The
     * split is not cosmetic: InspectionService::recordLotResult() rejects a
     * checklist row carrying bounds and a piece row lacking them, so the shape
     * written here is the shape the recording endpoint accepts.
     *
     * @param  iterable<array{parameter_name: string, parameter_type: string, unit_of_measure: ?string, nominal_value: mixed, tolerance_min: mixed, tolerance_max: mixed, is_critical: bool, notes: ?string, inspection_spec_item_id: ?int}>  $parameters
     *
     * @throws BusinessRuleException when nothing usable was supplied — an
     *         inspection with no rows cannot be completed later.
     */
    private function scaffoldLotChecklist(Inspection $inspection, iterable $parameters, int $measuredPieces): void
    {
        $timestamp = now()->toDateTimeString();
        $rows = [];

        foreach ($parameters as $parameter) {
            $name = trim((string) ($parameter['parameter_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $hasTolerance = $parameter['tolerance_min'] !== null && $parameter['tolerance_max'] !== null;
            $sampleIndices = $hasTolerance
                ? range(1, min($measuredPieces, max(1, (int) $inspection->sample_size)))
                : [1];

            foreach ($sampleIndices as $sampleIndex) {
                $rows[] = [
                    'inspection_id' => $inspection->id,
                    'inspection_spec_item_id' => $parameter['inspection_spec_item_id'] ?? null,
                    'sample_index' => $sampleIndex,
                    'parameter_name' => $name,
                    'parameter_type' => $parameter['parameter_type'],
                    'unit_of_measure' => $parameter['unit_of_measure'] ?? null,
                    'nominal_value' => $parameter['nominal_value'] ?? null,
                    'tolerance_min' => $hasTolerance ? $parameter['tolerance_min'] : null,
                    'tolerance_max' => $hasTolerance ? $parameter['tolerance_max'] : null,
                    'measured_value' => null,
                    'is_critical' => (bool) ($parameter['is_critical'] ?? false),
                    'is_pass' => null,
                    'notes' => $parameter['notes'] ?? null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        if ($rows === []) {
            throw new BusinessRuleException(
                'Lot checklist inspection requires at least one inspectable parameter.'
            );
        }

        foreach (array_chunk($rows, 500) as $batch) {
            InspectionMeasurement::query()->insert($batch);
        }
    }
```

- [ ] **Step 3: Replace the loop in `createIncomingFromPlan()`**

Replace the block that introduces `$measuredPieces`, `$checklistRows`, `$pieceRows` and `$allRows` (including its chunked insert) with:

```php
            $parameters = [];

            foreach ((array) $qualityPlan->parameters as $parameter) {
                $parameters[] = [
                    'parameter_name' => trim((string) ($parameter['parameter_name'] ?? '')),
                    'parameter_type' => $parameter['parameter_type'],
                    'unit_of_measure' => $parameter['unit_of_measure'] ?? null,
                    'nominal_value' => $parameter['nominal_value'] ?? null,
                    'tolerance_min' => $parameter['tolerance_min'] ?? null,
                    'tolerance_max' => $parameter['tolerance_max'] ?? null,
                    'is_critical' => (bool) ($parameter['is_critical'] ?? false),
                    'notes' => $parameter['notes'] ?? null,
                    'inspection_spec_item_id' => null,
                ];
            }

            $this->scaffoldLotChecklist($inspection, $parameters, $this->measuredPieces());
```

Then confirm the old locals are gone and the `per_unit` path survives:

```bash
grep -n "checklistRows\|pieceRows\|allRows" api/app/Modules/Quality/Services/InspectionService.php
grep -n "private function insertScaffoldRows" api/app/Modules/Quality/Services/InspectionService.php
```
Expected: no hits for the first; one hit for the second — `insertScaffoldRows()` must still exist for the `per_unit` path in `create()`.

- [ ] **Step 4: Run the tests to verify they still pass**

```bash
docker compose exec -T api php artisan test --filter='IncomingLotChecklistTest|ItemQualityPlanTest|IncomingQcTriggerTest|QualityPlanRolloutCommandTest'
```
Expected: PASS, same count as Step 1.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Quality/Services/InspectionService.php
git commit -m "refactor: task 4 — extract scaffoldLotChecklist for all three stages"
```

---

### Task 5: The certificate guard follows the mode

**Files:**
- Modify: `api/app/Modules/Quality/Services/CoCService.php` (`assertEvidenceSupportsCertificate()` and its docblock, around lines 217-259)
- Test: `api/tests/Feature/Quality/CoCEvidenceIntegrityTest.php` (extend)

**Interfaces:**
- Consumes: `LotDefectCounter::for()` from Task 1.
- Produces: no signature change. Existing per-unit error codes are preserved exactly (`COC_NO_MEASUREMENT_EVIDENCE`, `COC_EVIDENCE_INCOMPLETE`, `COC_EVIDENCE_CONTRADICTS_VERDICT`, `COC_EVIDENCE_SHORT_OF_SAMPLE`).

This task is inert until Task 6: nothing creates a `lot_checklist` outgoing inspection yet, and every existing test fabricates rows with `inspection_mode` omitted, so they stay on the `per_unit` default. Landing it first is what stops Task 6 from refusing certificates for routine lots.

- [ ] **Step 1: Write the failing tests**

Append to `api/tests/Feature/Quality/CoCEvidenceIntegrityTest.php`, inside the class:

```php
    /**
     * A lot may pass with defects inside the acceptance number; an AQL plan
     * exists precisely so that it can. Refusing its certificate made the two
     * verdicts disagree in the field most likely to reach a customer.
     */
    public function test_a_lot_checklist_that_passed_within_acceptance_is_certified(): void
    {
        $inspection = $this->fabricatePassedLotChecklist(defects: 1, acceptCount: 2);
        $this->fabricateMeasurement($inspection, 1, '10.0000', true);
        $this->fabricateMeasurement($inspection, 2, '10.0000', true);

        $out = app(CoCService::class)->buildBinaryForInspection($inspection->fresh());

        $this->assertStringContainsString('%PDF', substr($out['contents'], 0, 8));
    }

    public function test_a_lot_checklist_beyond_acceptance_is_refused(): void
    {
        $inspection = $this->fabricatePassedLotChecklist(defects: 3, acceptCount: 2);
        $this->fabricateMeasurement($inspection, 1, '10.0000', true);

        $this->assertCertificateRefused($inspection->fresh(), 'COC_EVIDENCE_CONTRADICTS_VERDICT');
    }

    /**
     * An all-visual spec produces no piece rows at all. Requiring a measured
     * value would make a certificate unissuable for it forever.
     */
    public function test_a_visual_only_lot_checklist_is_certified(): void
    {
        $inspection = $this->fabricatePassedLotChecklist(defects: 0, acceptCount: 1);
        $this->fabricateChecklistRow($inspection, 1, true);

        $this->assertSame(0, $inspection->fresh()->measurements->whereNotNull('tolerance_min')->count());
        $out = app(CoCService::class)->buildBinaryForInspection($inspection->fresh());
        $this->assertStringContainsString('%PDF', substr($out['contents'], 0, 8));
    }

    public function test_a_lot_checklist_without_a_reported_count_is_refused(): void
    {
        $inspection = $this->fabricatePassedLotChecklist(defects: 0, acceptCount: 2);
        $this->fabricateMeasurement($inspection, 1, '10.0000', true);
        $inspection->forceFill(['sample_defect_count' => null])->save();

        $this->assertCertificateRefused($inspection->fresh(), 'COC_EVIDENCE_INCOMPLETE');
    }

    public function test_a_lot_checklist_with_an_unmeasured_piece_row_is_refused(): void
    {
        $inspection = $this->fabricatePassedLotChecklist(defects: 0, acceptCount: 2);
        // Resolved, so it clears the unresolved check, but carries no reading.
        $this->fabricateMeasurement($inspection, 1, null, true);

        $this->assertCertificateRefused($inspection->fresh(), 'COC_EVIDENCE_INCOMPLETE');
    }

    public function test_a_lot_checklist_with_a_failed_critical_row_is_refused(): void
    {
        $inspection = $this->fabricatePassedLotChecklist(defects: 0, acceptCount: 2);
        $this->fabricateMeasurement($inspection, 1, '3.0000', false);

        $this->assertCertificateRefused($inspection->fresh(), 'COC_EVIDENCE_CONTRADICTS_VERDICT');
    }
```

Add these two helpers to the same class. `fabricatePassedOutgoing()` itself stays untouched so the existing per-unit tests keep the column default:

```php
    /**
     * A passed `lot_checklist` inspection. Built from the existing fabrication
     * because the service still creates `per_unit` outgoing inspections at this
     * point in the plan — Task 6 flips that.
     */
    private function fabricatePassedLotChecklist(int $defects, int $acceptCount, int $batch = 500, int $sample = 50): Inspection
    {
        $inspection = $this->fabricatePassedOutgoing(batch: $batch, sample: $sample);

        $inspection->forceFill([
            'inspection_mode' => InspectionMode::LotChecklist->value,
            'accept_count' => $acceptCount,
            'reject_count' => $acceptCount + 1,
            'sample_defect_count' => $defects,
        ])->save();

        return $inspection->fresh();
    }

    /** A lot-level checklist row: resolved, and deliberately without bounds. */
    private function fabricateChecklistRow(Inspection $inspection, int $sampleIndex, bool $isPass): InspectionMeasurement
    {
        $row = $this->fabricateMeasurement($inspection, $sampleIndex, null, $isPass);
        $row->forceFill(['parameter_name' => 'Packaging sealed', 'tolerance_min' => null, 'tolerance_max' => null])->save();

        return $row->fresh();
    }
```

Add `use App\Modules\Quality\Enums\InspectionMode;` to the file's imports.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker compose exec -T api php artisan test --filter='CoCEvidenceIntegrityTest'
```
Expected: FAIL — both new positive cases (`test_a_lot_checklist_that_passed_within_acceptance_is_certified`, `test_a_visual_only_lot_checklist_is_certified`) are refused with `COC_EVIDENCE_SHORT_OF_SAMPLE`, because the guard counts distinct `sample_index` values against `sample_size` regardless of mode.

- [ ] **Step 3: Rewrite the guard**

In `api/app/Modules/Quality/Services/CoCService.php`, replace the docblock's bullet list and the entire body of `assertEvidenceSupportsCertificate()` with:

```php
     * (imports, console tasks, direct SQL, a cascade that removed rows). Any
     * of the following means the certificate would misstate its own evidence:
     *
     *   - no measurement rows at all                 → nothing was measured
     *   - a row with is_pass = null                  → the lot is part-inspected
     *   - a critical row with is_pass = false        → evidence contradicts the verdict
     *   - defects above the acceptance number        → ditto
     *   - fewer sampled units than `sample_size`     → per-unit mode only; a
     *     lot-checklist sample is counted, not enumerated
     *   - no reported sample_defect_count, or an unmeasured dimension
     *     (lot-checklist mode)                       → the certificate's own
     *     sample claim has no evidence behind it
     *
     * Verified rather than trusted: this method re-reads the rows instead of
     * relying on `defect_count`, which is a snapshot taken at completion and
     * does not track later edits to the evidence. The defect count comes from
     * LotDefectCounter, the same implementation the completion verdict uses, so
     * a lot cannot pass on one formula and be refused a certificate on another.
     */
    private function assertEvidenceSupportsCertificate(Inspection $inspection): void
    {
        $rows = InspectionMeasurement::query()
            ->where('inspection_id', $inspection->getKey())
            ->get();

        if ($rows->isEmpty()) {
            throw new InspectionCertificateException(
                'CoC requires recorded inspection measurements; this inspection has none.',
                'COC_NO_MEASUREMENT_EVIDENCE',
            );
        }

        $unresolved = $rows->filter(static fn (InspectionMeasurement $row): bool => $row->is_pass === null)->count();
        if ($unresolved > 0) {
            throw new InspectionCertificateException(
                "CoC requires every sampled measurement to be resolved; {$unresolved} have no pass/fail recorded.",
                'COC_EVIDENCE_INCOMPLETE',
            );
        }

        $counted = LotDefectCounter::for($inspection, $rows);

        if ($counted['criticalFail']) {
            throw new InspectionCertificateException(
                'CoC cannot be issued: a critical characteristic was recorded as failed.',
                'COC_EVIDENCE_CONTRADICTS_VERDICT',
            );
        }

        if ($counted['defects'] > (int) $inspection->accept_count) {
            throw new InspectionCertificateException(
                "CoC cannot be issued: the recorded evidence ({$counted['defects']} defect(s)) "
                ."exceeds the acceptance number (Ac {$inspection->accept_count}).",
                'COC_EVIDENCE_CONTRADICTS_VERDICT',
            );
        }

        if ($inspection->inspection_mode === InspectionMode::LotChecklist) {
            // The certificate declares a sample that was counted, not
            // enumerated, so the count itself is the evidence of that sample —
            // and any dimension actually measured must carry its reading.
            if ($inspection->sample_defect_count === null) {
                throw new InspectionCertificateException(
                    'CoC requires the number of defective pieces found in the sample to be recorded.',
                    'COC_EVIDENCE_INCOMPLETE',
                );
            }

            $unmeasured = $rows
                ->filter(static fn (InspectionMeasurement $row): bool => $row->tolerance_min !== null || $row->tolerance_max !== null)
                ->filter(static fn (InspectionMeasurement $row): bool => $row->measured_value === null)
                ->count();

            if ($unmeasured > 0) {
                throw new InspectionCertificateException(
                    "CoC requires every measured dimension to carry a reading; {$unmeasured} piece row(s) have none.",
                    'COC_EVIDENCE_INCOMPLETE',
                );
            }

            return;
        }

        $sampledUnits = $rows->pluck('sample_index')->unique()->count();
        $declaredSample = (int) $inspection->sample_size;
        if ($declaredSample > 0 && $sampledUnits < $declaredSample) {
            throw new InspectionCertificateException(
                "CoC declares a sample of {$declaredSample} unit(s) but only {$sampledUnits} were measured.",
                'COC_EVIDENCE_SHORT_OF_SAMPLE',
            );
        }
    }
```

Add the imports at the top of `CoCService.php` (`InspectionMeasurement` is already there):

```php
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Support\LotDefectCounter;
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
docker compose exec -T api php artisan test --filter='CoCEvidenceIntegrityTest'
```
Expected: PASS — the six new lot-checklist cases, plus the untouched per-unit refusals (`COC_NO_MEASUREMENT_EVIDENCE`, `COC_EVIDENCE_SHORT_OF_SAMPLE`, the deleted-rows case) and `test_a_properly_inspected_lot_still_receives_its_certificate`.

- [ ] **Step 5: Confirm nothing else relies on the old blanket rule**

```bash
grep -rln "COC_EVIDENCE_\|COC_NO_MEASUREMENT" api/tests api/app
```
Expected: `CoCEvidenceIntegrityTest.php`, `CoCService.php`, and the exception class only. Any other test file is relying on the old `failing > 0` rule and must be read before continuing.

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Quality/Services/CoCService.php api/tests/Feature/Quality/CoCEvidenceIntegrityTest.php
git commit -m "feat: task 5 — certificate evidence guard follows the inspection mode"
```

---

### Task 6: Outgoing and in-process inspections become lot checklists

**Files:**
- Modify: `api/app/Modules/Quality/Services/InspectionService.php` (`create()` — the sample-plan branch, the `Inspection::query()->create([...])` call, and the `insertScaffoldRows(...)` call)
- Modify: `api/tests/Feature/Quality/CoCEvidenceIntegrityTest.php` (`passedOutgoingThroughService()` helper only)
- Test: `api/tests/Feature/Quality/OutgoingQcLotChecklistTest.php` (create)

**Interfaces:**
- Consumes: `scaffoldLotChecklist()` and `measuredPieces()` from Tasks 3 and 4.
- Produces: outgoing and in-process inspections created through `InspectionService::create()` carry `inspection_mode = lot_checklist`. `sample_size`, `aql_code`, `accept_count` and `reject_count` are unchanged — the AQL plan still produces them.

- [ ] **Step 1: Write the failing test**

Create `api/tests/Feature/Quality/OutgoingQcLotChecklistTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionMode;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A 2,000-piece lot takes an AQL sample of 125. Enumerating 125 measured units
 * per dimension is not how that sample is inspected — it is counted — so
 * outgoing and in-process inspections use lot_checklist mode: a checklist plus
 * a reported defect count, with a few pieces actually measured.
 */
class OutgoingQcLotChecklistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private InspectionSpec $spec;

    private InspectionService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'qc_inspector'], ['name' => 'QC Inspector']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->product = Product::create([
            'part_number' => 'LOT-'.substr(uniqid(), -6),
            'name' => 'Wiper Bushing',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '2.50',
            'is_active' => true,
        ]);

        $this->spec = InspectionSpec::create([
            'product_id' => $this->product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);
        // One toleranced parameter (piece rows) and one visual parameter
        // (a single checklist row).
        InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name' => 'Shaft OD',
            'parameter_type' => 'dimensional',
            'unit_of_measure' => 'mm',
            'nominal_value' => '10.0000',
            'tolerance_min' => '9.9000',
            'tolerance_max' => '10.1000',
            'is_critical' => true,
            'sort_order' => 1,
        ]);
        InspectionSpecItem::create([
            'inspection_spec_id' => $this->spec->id,
            'parameter_name' => 'Flash present',
            'parameter_type' => 'visual',
            'is_critical' => false,
            'sort_order' => 2,
        ]);
        $this->spec->ensureCurrentRevision();

        $this->svc = app(InspectionService::class);
    }

    public function test_outgoing_uses_lot_checklist_with_a_small_measured_set(): void
    {
        $inspection = $this->outgoingInspection(batch: 2000);

        $this->assertSame(InspectionMode::LotChecklist, $inspection->inspection_mode);
        $this->assertGreaterThan(
            100,
            (int) $inspection->sample_size,
            'The AQL sample is still the declared sample.',
        );

        $pieceRows = $inspection->measurements->whereNotNull('tolerance_min');
        $checklistRows = $inspection->measurements->whereNull('tolerance_min');

        $this->assertCount(5, $pieceRows, 'Measured pieces default to five, not the AQL sample size.');
        $this->assertCount(1, $checklistRows, 'The visual parameter becomes one checklist row.');
        $this->assertSame('Flash present', $checklistRows->first()->parameter_name);
        $this->assertSame(
            [1, 2, 3, 4, 5],
            $pieceRows->pluck('sample_index')->unique()->sort()->values()->all(),
        );
    }

    public function test_outgoing_lot_passes_and_fails_on_the_reported_defect_count(): void
    {
        $inspection = $this->outgoingInspection(batch: 2000);
        $accept = (int) $inspection->accept_count;
        $this->assertGreaterThan(0, $accept, 'A 2,000-piece lot must carry a non-zero acceptance number.');

        $this->recordVerdict($inspection, defects: $accept);
        $this->assertNotSame(
            InspectionStatus::Failed,
            $this->svc->complete($inspection->fresh(), $this->user)->status,
        );

        $failed = $this->outgoingInspection(batch: 2000);
        $this->recordVerdict($failed, defects: (int) $failed->accept_count + 1);
        $this->assertSame(
            InspectionStatus::Failed,
            $this->svc->complete($failed->fresh(), $this->user)->status,
        );
    }

    public function test_in_process_uses_lot_checklist(): void
    {
        $inspection = $this->svc->create([
            'stage' => InspectionStage::InProcess->value,
            'product_id' => $this->product->id,
            'batch_quantity' => 500,
        ], $this->user);

        $this->assertSame(InspectionMode::LotChecklist, $inspection->inspection_mode);
        $this->assertCount(1, $inspection->measurements->whereNull('tolerance_min'));
        $this->assertCount(5, $inspection->measurements->whereNotNull('tolerance_min'));
    }

    public function test_a_spec_with_only_toleranced_parameters_scaffolds_no_checklist_rows(): void
    {
        InspectionSpecItem::query()->where('parameter_name', 'Flash present')->delete();

        $inspection = $this->outgoingInspection(batch: 2000);

        $this->assertCount(0, $inspection->measurements->whereNull('tolerance_min'));
        $this->assertCount(5, $inspection->measurements->whereNotNull('tolerance_min'));

        // The defect count is then the only lot-level input, and the inspection
        // must still be completable through it.
        $this->recordVerdict($inspection, defects: 0);
        $this->assertNotSame(
            InspectionStatus::Draft,
            $this->svc->complete($inspection->fresh(), $this->user)->status,
        );
    }

    private function outgoingInspection(int $batch): Inspection
    {
        $so = SalesOrder::factory()->create();
        $wo = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'sales_order_id' => $so->id,
            'quantity_target' => $batch,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $wo->id,
            'batch_code' => 'CB-'.substr(uniqid(), -6),
            'good_count' => $batch,
            'reject_count' => 0,
            'recorded_at' => now(),
            'recorded_by' => $this->user->id,
        ]);

        return $this->svc->create([
            'stage' => InspectionStage::Outgoing->value,
            'product_id' => $this->product->id,
            'batch_quantity' => $batch,
            'work_order_output_id' => $output->id,
        ], $this->user);
    }

    /** Resolves every row and records the reported count, without completing. */
    private function recordVerdict(Inspection $inspection, int $defects): void
    {
        DB::table('inspection_measurements')
            ->where('inspection_id', $inspection->id)
            ->update(['is_pass' => true, 'measured_value' => '10.0000']);

        $inspection->forceFill(['sample_defect_count' => $defects])->save();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker compose exec -T api php artisan test --filter='OutgoingQcLotChecklistTest'
```
Expected: FAIL — `test_outgoing_uses_lot_checklist_with_a_small_measured_set` sees `per_unit` and 125 piece rows instead of 5.

- [ ] **Step 3: Implement the mode change**

In `api/app/Modules/Quality/Services/InspectionService.php`, inside `create()`, after the `if/elseif/else` block that sets `$sample`, `$code`, `$accept` and `$reject`, and before the `return DB::transaction(...)`, add:

```php
        // Counting the sample instead of enumerating it is the point: the AQL
        // sample is inspected visually for defectives, and only a few pieces are
        // measured. Legacy rows keep the per-unit matrix; nothing new creates one.
        $lotChecklist = in_array(
            $stage,
            [InspectionStage::Incoming, InspectionStage::InProcess, InspectionStage::Outgoing],
            true,
        );
        $measuredPieces = $lotChecklist ? $this->measuredPieces() : 0;
```

Add `'inspection_mode' => ($lotChecklist ? InspectionMode::LotChecklist : InspectionMode::PerUnit)->value,` to the `Inspection::query()->create([...])` array immediately after its `'status' => ...` line, and add `$lotChecklist, $measuredPieces` to the `use (...)` clause of that closure.

Replace the `$this->insertScaffoldRows($insp->id, $sample, $spec->items, static function (...) {...});` call with:

```php
            if ($lotChecklist) {
                $parameters = $spec->items->map(static fn (InspectionSpecItem $item): array => [
                    'parameter_name' => (string) $item->parameter_name,
                    'parameter_type' => $item->parameter_type->value,
                    'unit_of_measure' => $item->unit_of_measure,
                    'nominal_value' => $item->nominal_value,
                    'tolerance_min' => $item->tolerance_min,
                    'tolerance_max' => $item->tolerance_max,
                    'is_critical' => (bool) $item->is_critical,
                    'notes' => null,
                    'inspection_spec_item_id' => (int) $item->id,
                ])->all();

                $this->scaffoldLotChecklist($insp, $parameters, $measuredPieces);
            } else {
                $this->insertScaffoldRows(
                    $insp->id,
                    $sample,
                    $spec->items,
                    static function (int $sampleIndex, InspectionSpecItem $item, int $inspectionId, string $timestamp): array {
                        return [
                            'inspection_id' => $inspectionId,
                            'inspection_spec_item_id' => $item->id,
                            'sample_index' => $sampleIndex,
                            'parameter_name' => $item->parameter_name,
                            'parameter_type' => $item->parameter_type->value,
                            'unit_of_measure' => $item->unit_of_measure,
                            'nominal_value' => $item->nominal_value,
                            'tolerance_min' => $item->tolerance_min,
                            'tolerance_max' => $item->tolerance_max,
                            'measured_value' => null,
                            'is_critical' => $item->is_critical,
                            'is_pass' => null,
                            'notes' => null,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ];
                    },
                );
            }
```

`InspectionMode` and `InspectionStage` are already imported (both are used by `complete()`); confirm with `grep -n "use App\\\\Modules\\\\Quality\\\\Enums\\\\InspectionMode" api/app/Modules/Quality/Services/InspectionService.php`.

- [ ] **Step 4: Update the CoC test helper**

In `api/tests/Feature/Quality/CoCEvidenceIntegrityTest.php`, inside `passedOutgoingThroughService()`, insert this between the `recordMeasurements()` call and the `complete()` call:

```php
        // Lot-checklist inspections cannot complete without a reported count.
        // Zero is correct here and is not an attestation shortcut: this helper
        // has just written an in-tolerance reading to every measured piece.
        $inspection = $inspection->fresh();
        if ($inspection->inspection_mode === InspectionMode::LotChecklist) {
            $inspection->forceFill(['sample_defect_count' => 0])->save();
            $inspection = $inspection->fresh();
        }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
docker compose exec -T api php artisan test --filter='OutgoingQcLotChecklistTest|CoCEvidenceIntegrityTest|OutgoingQcIdempotencyTest|InProcessQcTriggerTest|QualitySamplingBoundsTest|InspectionMeasurementContractTest|IncomingLotChecklistTest'
```
Expected: PASS. `CoCEvidenceIntegrityTest` needs Task 5 in place — if the control case fails with `COC_EVIDENCE_SHORT_OF_SAMPLE`, apply Task 5 and re-run before continuing.

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Quality/Services/InspectionService.php api/tests/Feature/Quality/OutgoingQcLotChecklistTest.php api/tests/Feature/Quality/CoCEvidenceIntegrityTest.php
git commit -m "feat: task 6 — outgoing and in-process inspections use lot_checklist mode"
```

---

### Task 7: The capture panel is stage-agnostic in the SPA

**Files:**
- Rename: `spa/src/pages/quality/inspections/components/IncomingLotChecklist.tsx` → `LotResultPanel.tsx`
- Rename: `spa/src/pages/quality/inspections/components/IncomingLotChecklist.test.ts` → `computeLotChecklistVerdict.test.ts`
- Modify: `spa/src/pages/quality/inspections/detail.tsx:36` (import) and `:399-400` (render)
- Test: `spa/src/pages/quality/inspections/components/computeLotChecklistVerdict.test.ts` (renamed, content unchanged)

**Interfaces:**
- Consumes: the backend contract from Tasks 5 and 6 — an inspection whose `inspection_mode` is `lot_checklist` has checklist rows (`sample_index` 1, no tolerance) and piece rows (tolerance, a few `sample_index` values).
- Produces: `LotResultPanel({ inspection, isTerminal })` — same props, renamed export.

- [ ] **Step 1: Rename the files**

```bash
git mv spa/src/pages/quality/inspections/components/IncomingLotChecklist.tsx spa/src/pages/quality/inspections/components/LotResultPanel.tsx
git mv spa/src/pages/quality/inspections/components/IncomingLotChecklist.test.ts spa/src/pages/quality/inspections/components/computeLotChecklistVerdict.test.ts
```

- [ ] **Step 2: Generalise the panel's docblock and export**

In `LotResultPanel.tsx`, change the component declaration from `export function IncomingLotChecklist({ inspection, isTerminal }: LotChecklistProps)` to `export function LotResultPanel({ inspection, isTerminal }: LotChecklistProps)`, and replace the file's opening docblock with:

```tsx
/**
 * Lot capture panel — the recording surface for every inspection whose
 * inspection_mode is `lot_checklist` (incoming, in-process and outgoing).
 *
 * The inspector ticks the lot-level checklist, counts the defective pieces
 * found in the sample, and measures a few pieces per toleranced dimension.
 * Server is authoritative for the verdict.
 */
```

- [ ] **Step 3: Update the detail page**

In `spa/src/pages/quality/inspections/detail.tsx`, change line 36 to:

```tsx
import { LotResultPanel } from './components/LotResultPanel';
```

and lines 399-400 to:

```tsx
          {data.inspection_mode === 'lot_checklist' ? (
            <LotResultPanel inspection={data} isTerminal={isTerminal} />
```

Confirm nothing references the old name:

```bash
grep -rn "IncomingLotChecklist" spa/src
```
Expected: no hits.

- [ ] **Step 4: Confirm the mode gate covers all three stages**

```bash
grep -n "lot_checklist" spa/src/pages/quality/inspections/detail.tsx
```
Expected: hits at the three existing gates (lines 294, 399, 620) — all keyed on `inspection_mode`, not on `stage`. No stage list needs widening. If any gate reads `stage === 'incoming'`, that is a bug for this task: change it to the mode.

- [ ] **Step 5: Run the checks**

```bash
cd spa && npx tsc --noEmit && npx vitest run src/pages/quality/inspections
```
Expected: `tsc` clean; the renamed component test and `detail.test.tsx` both pass.

- [ ] **Step 6: Commit**

```bash
git add spa/src/pages/quality/inspections
git commit -m "feat: task 7 — rename the capture panel for use by every lot-checklist stage"
```

---

### Task 8: An incoming checklist can never resolve to zero rows

**Files:**
- Create: `api/app/Modules/Quality/Support/IncomingChecklist.php`
- Modify: `api/app/Modules/Quality/Services/InspectionService.php` (the checklist block in `createIncomingForItem()`, around lines 201-226)
- Modify: `spa/src/pages/quality/inspections/components/LotResultPanel.tsx` (warning strip when an inspection has no rows)
- Test: `api/tests/Feature/Quality/IncomingLotChecklistTest.php` (extend)

**Interfaces:**
- Produces: `IncomingChecklist::defaults(SettingsService $settings): array<int, array{parameter_name: string, is_critical: bool}>` — never empty.

**Deliberate deviation from the spec:** the spec asks for a `BusinessRuleException` when nothing usable is configured. With a code-level constant as the last resort that branch is unreachable, and dead code is worse than none: the throw for an unusable set lives in `scaffoldLotChecklist()` (Task 4), where it *is* reachable — a quality plan whose parameters are all blank hits it. The guarantee the spec wanted — an inspection always has something to tick, or the GRN says why not — is met by the constant plus that throw.

- [ ] **Step 1: Write the failing tests**

Append to `api/tests/Feature/Quality/IncomingLotChecklistTest.php`, inside the class:

```php
    public function test_incoming_without_a_configured_checklist_falls_back_to_the_built_in_default(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'quality.incoming.default_checklist')
            ->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $grn = $this->createGrnWith($this->item, 100);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $this->assertCount(
            5,
            $inspection->measurements,
            'An empty checklist setting must not produce an uninspectable lot.',
        );
        $this->assertTrue(
            $inspection->measurements->contains(fn ($m) => $m->is_critical),
            'The built-in default must keep its critical items.',
        );
    }

    public function test_incoming_skips_blank_configured_entries_but_keeps_the_real_ones(): void
    {
        \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'quality.incoming.default_checklist')
            ->update(['value' => json_encode([
                ['parameter_name' => '   ', 'is_critical' => true],
                ['parameter_name' => 'Pallets are dry on arrival', 'is_critical' => false],
            ])]);
        \Illuminate\Support\Facades\Cache::flush();

        $grn = $this->createGrnWith($this->item, 100);
        $inspection = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();

        $this->assertCount(1, $inspection->measurements);
        $this->assertSame('Pallets are dry on arrival', $inspection->measurements->first()->parameter_name);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
docker compose exec -T api php artisan test --filter='built_in_default|blank_configured_entries'
```
Expected: FAIL — the first sees zero measurements for the inspection.

- [ ] **Step 3: Add the support class**

Create `api/app/Modules/Quality/Support/IncomingChecklist.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Quality\Support;

use App\Common\Services\SettingsService;

/**
 * The incoming visual checklist.
 *
 * The setting is operator-editable, so it can be emptied, mistyped or missing
 * (a database that has not run the seeding migration). Falling through to
 * nothing produced an inspection with no rows to tick — an uninspectable lot
 * that could not be completed at all. The built-in list is the same five items
 * migration 2026_09_24_100100 seeds the setting with.
 */
final class IncomingChecklist
{
    /** @var array<int, array{parameter_name: string, is_critical: bool}> */
    private const DEFAULTS = [
        ['parameter_name' => 'Delivery documents (DR/invoice) match the PO item and quantity', 'is_critical' => true],
        ['parameter_name' => 'Certificate of Analysis / mill certificate received and matches the lot', 'is_critical' => true],
        ['parameter_name' => 'Packaging sealed and undamaged (no wetness, contamination or tampering)', 'is_critical' => true],
        ['parameter_name' => 'Labels show the correct item code, grade/colour and lot number', 'is_critical' => true],
        ['parameter_name' => 'Condition on arrival acceptable (storage/handling)', 'is_critical' => false],
    ];

    /**
     * @return array<int, array{parameter_name: string, is_critical: bool}>
     */
    public static function defaults(SettingsService $settings): array
    {
        $configured = self::normalize($settings->get('quality.incoming.default_checklist', []));

        return $configured !== [] ? $configured : self::normalize(self::DEFAULTS);
    }

    /**
     * @return array<int, array{parameter_name: string, is_critical: bool}>
     */
    private static function normalize(mixed $raw): array
    {
        $rows = [];

        foreach ((array) $raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['parameter_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $rows[] = ['parameter_name' => $name, 'is_critical' => (bool) ($entry['is_critical'] ?? false)];
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Use it in `createIncomingForItem()`**

Read the existing block first to preserve every column its insert needs:

```bash
sed -n '190,240p' api/app/Modules/Quality/Services/InspectionService.php
```

Then replace the part that reads the setting and builds `$checklistRows` with:

```php
            // The setting is operator-editable and can be empty; falling back to
            // the built-in list is what keeps the inspection inspectable.
            $defaultChecklist = IncomingChecklist::defaults($this->settings);
            $timestamp = now()->toDateTimeString();
            $checklistRows = [];

            foreach ($defaultChecklist as $check) {
                $checklistRows[] = [
                    'inspection_id' => $inspection->id,
                    'sample_index' => 1,
                    'parameter_name' => $check['parameter_name'],
                    'parameter_type' => InspectionParameterType::Visual->value,
                    'is_critical' => $check['is_critical'],
                    'is_pass' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
```

Keep any additional null-valued columns the existing rows set explicitly (`tolerance_min`, `tolerance_max`, `measured_value`, `notes`), and keep the existing chunked insert as it is. Add the import `use App\Modules\Quality\Support\IncomingChecklist;`.

- [ ] **Step 5: Add the panel's safety strip**

In `spa/src/pages/quality/inspections/components/LotResultPanel.tsx`, immediately after the summary strip's closing `</Panel>` and before the section 1 block, insert:

```tsx
      {/* The backend always seeds checklist rows; if this ever renders, the
          inspection has no recording surface and completing it would throw.
          Surfaced rather than silently showing an empty form. */}
      {checklistMeasurements.length === 0 && numericMeasurements.length === 0 && (
        <Panel>
          <Chip variant="danger">
            This inspection has no checklist rows — it cannot be recorded. Cancel it and ask an
            administrator to check the incoming QC checklist setting.
          </Chip>
        </Panel>
      )}
```

`Panel`, `Chip` and the two measurement memos are already present in that file — no import changes needed.

- [ ] **Step 6: Run the checks**

```bash
docker compose exec -T api php artisan test --filter='IncomingLotChecklistTest|IncomingQcTriggerTest'
```
```bash
cd spa && npx tsc --noEmit && npx vitest run src/pages/quality/inspections
```
Expected: all PASS; `tsc` clean.

- [ ] **Step 7: Commit**

```bash
git add api/app/Modules/Quality/Support/IncomingChecklist.php api/app/Modules/Quality/Services/InspectionService.php api/tests/Feature/Quality/IncomingLotChecklistTest.php spa/src/pages/quality/inspections/components/LotResultPanel.tsx
git commit -m "feat: task 8 — the incoming checklist falls back to its built-in items"
```

---

### Task 9: Review every pending incoming inspection for a receipt on one screen

**Files:**
- Create: `spa/src/pages/quality/inspections/lot-review.tsx`
- Create: `spa/src/pages/quality/inspections/lot-review.test.tsx`
- Modify: `spa/src/routes/qualityRoutes.tsx` (lazy import + route, declared before `/quality/inspections/:id`)
- Modify: `spa/src/pages/inventory/grn/detail.tsx` (entry point on the QC banner)

**Interfaces:**
- Consumes: `inspectionsApi.list({ entity_type: 'grn', entity_id, per_page })`, `inspectionsApi.show(id)`, `inspectionsApi.recordLotResult(id, data)` — all existing. No new backend route.
- Produces: the page component (default export) at `/quality/inspections/lot-review/:grnId`.

A receipt with thirty lines currently means thirty page loads. This screen does not replace the GRN single-screen flow (`GrnService::receiveWithQc()`), which is a receive-time terminal verdict for the whole receipt; it gives the reviewer a per-line verdict afterwards.

- [ ] **Step 1: Write the failing test**

Create `spa/src/pages/quality/inspections/lot-review.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import LotReviewPage from './lot-review';
import { inspectionsApi } from '@/api/quality/inspections';
import type { Inspection } from '@/types/quality';

vi.mock('@/api/quality/inspections', () => ({
  inspectionsApi: { list: vi.fn(), show: vi.fn(), recordLotResult: vi.fn() },
}));

function inspection(overrides: Record<string, unknown> = {}): Inspection {
  return {
    id: 'insp-1',
    inspection_number: 'QC-202609-0001',
    stage: 'incoming',
    status: 'draft',
    inspection_mode: 'lot_checklist',
    batch_quantity: 2000,
    sample_size: 125,
    accept_count: 3,
    reject_count: 4,
    sample_defect_count: null,
    measurements: [
      {
        id: 'm-1',
        sample_index: 1,
        parameter_name: 'Packaging sealed',
        is_critical: true,
        is_pass: null,
        measured_value: null,
        tolerance_min: null,
        tolerance_max: null,
      },
    ],
    item: { id: 'item-1', code: 'RES-001', name: 'ABS Resin' },
    ...overrides,
  } as unknown as Inspection;
}

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/quality/inspections/lot-review/grn-1']}>
        <Routes>
          <Route path="/quality/inspections/lot-review/:grnId" element={<LotReviewPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('LotReviewPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(inspectionsApi.list).mockResolvedValue({
      data: [inspection()],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
    } as never);
    vi.mocked(inspectionsApi.show).mockResolvedValue(inspection());
    vi.mocked(inspectionsApi.recordLotResult).mockResolvedValue(inspection({ status: 'passed' }));
  });

  it('will not submit a line that has not been attested', async () => {
    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: /submit all/i }));

    expect(inspectionsApi.recordLotResult).not.toHaveBeenCalled();
    expect(await screen.findByText(/not attested/i)).toBeInTheDocument();
  });

  it('submits an attested line with the counted defects', async () => {
    renderPage();

    await userEvent.click(await screen.findByRole('checkbox', { name: /checked/i }));
    await userEvent.type(screen.getByLabelText(/defective pieces, QC-202609-0001/i), '0');
    await userEvent.click(screen.getByRole('button', { name: /submit all/i }));

    await waitFor(() => expect(inspectionsApi.recordLotResult).toHaveBeenCalledTimes(1));
    const [, payload] = vi.mocked(inspectionsApi.recordLotResult).mock.calls[0];
    expect(payload).toMatchObject({ sample_defect_count: 0, complete: true });
  });

  it('never invents a defect count for an attested line', async () => {
    renderPage();

    await userEvent.click(await screen.findByRole('checkbox', { name: /checked/i }));
    await userEvent.click(screen.getByRole('button', { name: /submit all/i }));

    expect(inspectionsApi.recordLotResult).not.toHaveBeenCalled();
    expect(await screen.findByText(/number of defective pieces/i)).toBeInTheDocument();
  });

  it("keeps going after one line is refused, and shows that line's message", async () => {
    vi.mocked(inspectionsApi.list).mockResolvedValue({
      data: [inspection(), inspection({ id: 'insp-2', inspection_number: 'QC-202609-0002' })],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 2 },
    } as never);
    vi.mocked(inspectionsApi.recordLotResult)
      .mockRejectedValueOnce({ response: { data: { message: 'Inspection is awaiting checker review.' } } })
      .mockResolvedValueOnce(inspection({ id: 'insp-2', status: 'passed' }));

    renderPage();

    const toggles = await screen.findAllByRole('checkbox', { name: /checked/i });
    for (const toggle of toggles) {
      await userEvent.click(toggle);
    }
    // Each line carries its own count; both must be filled for either to post.
    await userEvent.type(screen.getByLabelText(/defective pieces, QC-202609-0001/i), '0');
    await userEvent.type(screen.getByLabelText(/defective pieces, QC-202609-0002/i), '0');
    await userEvent.click(screen.getByRole('button', { name: /submit all/i }));

    await waitFor(() => expect(inspectionsApi.recordLotResult).toHaveBeenCalledTimes(2));
    expect(await screen.findByText(/awaiting checker review/i)).toBeInTheDocument();
  });

  it('records a critical item the inspector unticked as a failure, without the attestation', async () => {
    vi.mocked(inspectionsApi.recordLotResult).mockResolvedValue(inspection({ status: 'failed' }));

    renderPage();

    await userEvent.click(await screen.findByRole('button', { name: /^checklist/i }));
    // A recorded failure is itself evidence the sample was inspected, so the
    // "no defects found" attestation is deliberately not required beside it.
    await userEvent.click(screen.getByRole('checkbox', { name: /packaging sealed/i }));
    // ...and unticking it enables the count input without the attestation.
    await userEvent.type(screen.getByLabelText(/defective pieces, QC-202609-0001/i), '1');
    await userEvent.click(screen.getByRole('button', { name: /submit all/i }));

    await waitFor(() => expect(inspectionsApi.recordLotResult).toHaveBeenCalledTimes(1));
    const [, payload] = vi.mocked(inspectionsApi.recordLotResult).mock.calls[0];
    expect(payload.checklist).toEqual([{ id: 'm-1', is_pass: false, notes: null }]);
    expect(payload.sample_defect_count).toBe(1);
    expect(await screen.findByText(/^failed\.$/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd spa && npx vitest run src/pages/quality/inspections/lot-review.test.tsx
```
Expected: FAIL — cannot resolve `./lot-review`.

- [ ] **Step 3: Write the page**

Create `spa/src/pages/quality/inspections/lot-review.tsx`:

```tsx
/**
 * Lot review — every pending incoming inspection for one receipt on one screen.
 *
 * Nothing new is recorded here: each row posts to the same per-inspection
 * lot-result endpoint the detail page uses, so the verdict, maker-checker
 * routing and NCR escalation all run exactly once, in existing code. Rows are
 * submitted sequentially so a refusal on one line leaves the rest attempted and
 * reports that line's own server message.
 *
 * A line is submittable on either of two signals — the "no defects found"
 * attestation, or a checklist item unticked as failed. A recorded failure is
 * itself evidence the sample was inspected, so requiring the attestation beside
 * it would be contradictory. A line with neither is refused with a reason, and
 * a line whose dimensions still need readings is sent to the inspection page
 * rather than submitted half-measured.
 */
import { Fragment, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuArrowLeft, LuCheck, LuChevronDown, LuChevronRight } from '@/lib/icons';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import type { Inspection, InspectionMeasurement } from '@/types/quality';

const PENDING_STATUSES = ['draft', 'in_progress'];

type RowState = {
  attested: boolean;
  /**
   * Starts empty and is never pre-filled. A `0` the validator never typed would
   * claim the sample was counted clean — the same unattested zero
   * `GrnService::fastCompleteInspection()` writes, which this screen exists to
   * avoid. Blank until someone types it.
   */
  defects: string;
  /** measurement id → ticked as failed; absent means it passed. */
  failed: Record<string, boolean>;
  expanded: boolean;
  /** Loaded on first expand; the list endpoint omits measurements. */
  checklist: InspectionMeasurement[] | null;
  outcome: { kind: 'done' | 'error'; message: string } | null;
};

const emptyRow = (): RowState => ({
  attested: false,
  defects: '',
  failed: {},
  expanded: false,
  checklist: null,
  outcome: null,
});

export default function LotReviewPage() {
  const { grnId = '' } = useParams();
  const [rows, setRows] = useState<Record<string, RowState>>({});
  const [submitting, setSubmitting] = useState(false);

  const list = useQuery({
    queryKey: ['quality', 'inspections', 'lot-review', grnId],
    queryFn: () => inspectionsApi.list({ entity_type: 'grn', entity_id: grnId, per_page: 100 }),
    enabled: Boolean(grnId),
  });

  const pending = useMemo(
    () => (list.data?.data ?? []).filter((i) => PENDING_STATUSES.includes(i.status)),
    [list.data],
  );

  const stateFor = (id: string): RowState => rows[id] ?? emptyRow();
  const patch = (id: string, next: Partial<RowState>) =>
    setRows((s) => ({ ...s, [id]: { ...(s[id] ?? emptyRow()), ...next } }));

  const hasUnfilledMeasurements = (inspection: Inspection) =>
    (inspection.measurements ?? []).some(
      // `measured_value` is `number | null` on the type, not a string — the
      // empty-string case exists only in the form input, not in the response.
      (m) => m.tolerance_min !== null && m.measured_value === null,
    );

  const toggleChecklist = async (inspection: Inspection) => {
    const row = stateFor(inspection.id);

    if (row.expanded) {
      patch(inspection.id, { expanded: false });
      return;
    }

    patch(inspection.id, { expanded: true });

    if (row.checklist === null) {
      try {
        const detail = await inspectionsApi.show(inspection.id);
        patch(inspection.id, {
          checklist: (detail.measurements ?? []).filter(
            (m) => m.tolerance_min === null && m.tolerance_max === null,
          ),
        });
      } catch {
        patch(inspection.id, {
          checklist: [],
          outcome: { kind: 'error', message: 'Could not load this line’s checklist.' },
        });
      }
    }
  };

  const submitAll = async () => {
    setSubmitting(true);

    for (const inspection of pending) {
      const row = stateFor(inspection.id);
      const failedIds = Object.entries(row.failed).filter(([, isFailed]) => isFailed).map(([id]) => id);

      // A recorded failure is itself evidence the sample was inspected, so
      // either signal satisfies the gate — but a blank row is never submitted.
      if (!row.attested && failedIds.length === 0) {
        patch(inspection.id, {
          outcome: {
            kind: 'error',
            message: 'Not attested — tick "Checked — no defects found", or record the failed item(s).',
          },
        });
        continue;
      }

      // The count is entered, never assumed. Mirrors the backend's own refusal
      // in `complete()` rather than letting a blank parse to 0 on the way out.
      if (row.defects.trim() === '') {
        patch(inspection.id, {
          outcome: {
            kind: 'error',
            message: 'Enter the number of defective pieces found in the sample (0 if none).',
          },
        });
        continue;
      }

      try {
        // The list omits measurements; the recording endpoint needs their ids,
        // so the row is hydrated before it is submitted.
        const detail = await inspectionsApi.show(inspection.id);

        if (hasUnfilledMeasurements(detail)) {
          patch(inspection.id, {
            outcome: {
              kind: 'error',
              message: 'Measurements are missing — open the inspection and record them.',
            },
          });
          continue;
        }

        const checklistRows = (detail.measurements ?? []).filter(
          (m) => m.tolerance_min === null && m.tolerance_max === null,
        );

        // Non-critical items stay as ticked; the inspector only unticks what
        // they found wrong, so the panel's own convention is preserved.
        if (failedIds.some((id) => !checklistRows.some((m) => m.id === id))) {
          patch(inspection.id, {
            outcome: { kind: 'error', message: 'A recorded failure is not a checklist item on this inspection.' },
          });
          continue;
        }

        const payload = {
          checklist: checklistRows.map((m) => ({
            id: m.id,
            is_pass: !row.failed[m.id],
            notes: null,
          })),
          measurements: (detail.measurements ?? [])
            .filter((m) => m.tolerance_min !== null || m.tolerance_max !== null)
            // `RecordLotResultData` takes the decimal as a string, so it is not
            // parsed into a float here.
            .map((m) => ({
              id: m.id,
              measured_value: m.measured_value === null ? null : String(m.measured_value),
            })),
          sample_defect_count: Number(row.defects),
          complete: true,
        };

        const result = await inspectionsApi.recordLotResult(inspection.id, payload);
        patch(inspection.id, {
          outcome: {
            kind: 'done',
            message:
              result.status === 'awaiting_review'
                ? 'Submitted for checker review.'
                : result.status === 'passed'
                  ? 'Passed.'
                  : result.status === 'failed'
                    ? 'Failed.'
                    : result.status,
          },
        });
      } catch (error) {
        const message =
          (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
          'Submit failed.';
        patch(inspection.id, { outcome: { kind: 'error', message } });
      }
    }

    setSubmitting(false);
    void list.refetch();
  };

  return (
    <div className="px-5 py-4 space-y-4">
      <Panel title="Lot review" meta="Every pending incoming inspection for this receipt">
        <Link to={`/inventory/grn/${grnId}`} className="text-primary hover:underline text-sm">
          <span className="inline-flex items-center gap-1">
            <LuArrowLeft size={14} /> Back to receipt
          </span>
        </Link>
      </Panel>

      <Panel title="Pending inspections" meta={`${pending.length} line(s)`}>
        {list.isLoading ? (
          <p className="text-sm text-muted">Loading…</p>
        ) : list.isError ? (
          <div className="space-y-2">
            <p className="text-sm text-danger-fg">Could not load this receipt's inspections.</p>
            <Button variant="secondary" size="sm" onClick={() => void list.refetch()}>
              Retry
            </Button>
          </div>
        ) : pending.length === 0 ? (
          <p className="text-sm text-muted">Nothing pending — every line has a recorded verdict.</p>
        ) : (
          <table className={tableCls}>
            <thead>
              <tr className={theadTrCls}>
                <Th>Inspection</Th>
                <Th align="right">Batch</Th>
                <Th align="right">Sample</Th>
                <Th align="right">Ac</Th>
                <Th>Sample inspected</Th>
                <Th align="right">Defective pcs</Th>
                <Th>Checklist</Th>
                <Th>Result</Th>
              </tr>
            </thead>
            <tbody>
              {pending.map((inspection) => {
                const row = stateFor(inspection.id);
                const failedCount = Object.values(row.failed).filter(Boolean).length;
                return (
                  <Fragment key={inspection.id}>
                  <tr className={trCls}>
                    <Td>
                      <Link
                        className="text-primary hover:underline"
                        to={`/quality/inspections/${inspection.id}`}
                      >
                        <span className="font-mono">{inspection.inspection_number}</span>
                      </Link>
                      <div className="text-2xs text-muted">{inspection.item?.name ?? '—'}</div>
                    </Td>
                    <Td align="right" mono>
                      {inspection.batch_quantity}
                    </Td>
                    <Td align="right" mono>
                      {inspection.sample_size}
                    </Td>
                    <Td align="right" mono>
                      {inspection.accept_count}
                    </Td>
                    <Td>
                      <Checkbox
                        id={`attested-${inspection.id}`}
                        checked={row.attested}
                        onChange={(e) => patch(inspection.id, { attested: e.target.checked })}
                        label="Checked — no defects found"
                      />
                    </Td>
                    <Td align="right" mono>
                      <Input
                        fieldSize="sm"
                        type="number"
                        min="0"
                        max={inspection.sample_size}
                        disabled={(!row.attested && failedCount === 0) || row.outcome?.kind === 'done'}
                        containerClassName="inline-flex w-20"
                        className="text-right font-mono tabular-nums"
                        aria-label={`Defective pieces, ${inspection.inspection_number}`}
                        value={row.defects}
                        onChange={(e) => patch(inspection.id, { defects: e.target.value })}
                      />
                    </Td>
                    <Td>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        loading={row.expanded && row.checklist === null}
                        icon={row.expanded ? <LuChevronDown size={12} /> : <LuChevronRight size={12} />}
                        onClick={() => void toggleChecklist(inspection)}
                      >
                        Checklist{failedCount > 0 && ` · ${failedCount} failed`}
                      </Button>
                    </Td>
                    <Td>
                      {row.outcome ? (
                        <Chip variant={row.outcome.kind === 'done' ? 'success' : 'danger'}>
                          {row.outcome.message}
                        </Chip>
                      ) : (
                        <span className="text-2xs text-muted">Pending</span>
                      )}
                    </Td>
                  </tr>
                  {row.expanded && (
                    <tr className="border-b border-subtle bg-subtle/40">
                      <Td colSpan={8}>
                        {row.checklist === null ? (
                          <span className="text-2xs text-muted">Loading checklist…</span>
                        ) : row.checklist.length === 0 ? (
                          <span className="text-2xs text-muted">
                            This line has no checklist items — only measured dimensions.
                          </span>
                        ) : (
                          <div className="flex flex-wrap gap-x-6 gap-y-1">
                            {row.checklist.map((item) => (
                              <Checkbox
                                key={item.id}
                                id={`failed-${inspection.id}-${item.id}`}
                                checked={!row.failed[item.id]}
                                onChange={(e) =>
                                  patch(inspection.id, {
                                    failed: { ...row.failed, [item.id]: !e.target.checked },
                                  })
                                }
                                label={
                                  <>
                                    {item.parameter_name}
                                    {item.is_critical && (
                                      <Chip variant="warning" className="ml-1">
                                        Critical
                                      </Chip>
                                    )}
                                  </>
                                }
                              />
                            ))}
                          </div>
                        )}
                      </Td>
                    </tr>
                  )}
                  </Fragment>
                );
              })}
            </tbody>
          </table>
        )}
      </Panel>

      {pending.length > 0 && (
        <Panel>
          <Button
            variant="primary"
            size="sm"
            icon={<LuCheck size={14} />}
            loading={submitting}
            onClick={() => void submitAll()}
          >
            Submit all
          </Button>
          <p className="text-2xs text-muted mt-2">
            Each line is recorded separately. A line whose dimensions still need readings is left
            for the inspection page instead.
          </p>
        </Panel>
      )}
    </div>
  );
}
```

Verify the `Th`/`Td` prop names (`align`, `mono`) and the `Chip` variants (`success`/`danger`) against `LotResultPanel.tsx`, which renders the same table primitives. `tsc` names any mismatch.

- [ ] **Step 4: Register the route**

In `spa/src/routes/qualityRoutes.tsx`, add beside the other quality lazy imports:

```tsx
const LotReviewPage = lazy(() => import('@/pages/quality/inspections/lot-review'));
```

and declare the route **before** `/quality/inspections/:id`, matching how `/quality/inspections/new` is already ordered:

```tsx
        <Route path="/quality/inspections/lot-review/:grnId"
          element={<PermissionGuard permission="quality.inspections.manage"><LotReviewPage /></PermissionGuard>} />
```

- [ ] **Step 5: Add the entry point on the receipt**

In `spa/src/pages/inventory/grn/detail.tsx`, the existing block at line 336 renders "Retry incoming QC" when `incomingQcNeedsAttention && can('quality.inspections.manage')`. Add a sibling immediately after it:

```tsx
            {incomingQcNeedsAttention && can('quality.inspections.manage') && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LuListChecks size={14} />}
                onClick={() => navigate(`/quality/inspections/lot-review/${id}`)}
              >
                Review all pending QC lines
              </Button>
            )}
```

`navigate` is already in scope (used at line 334). Confirm the icon exists before using it:

```bash
grep -n "LuListChecks\|LuRefreshCw" spa/src/lib/icons.ts
```
If `LuListChecks` is absent, use `LuCheck` and add it to the existing `@/lib/icons` import in that file.

- [ ] **Step 6: Run the checks**

```bash
cd spa && npx tsc --noEmit && npx vitest run src/pages/quality/inspections/lot-review.test.tsx
```
Expected: `tsc` clean, 5 tests pass.

- [ ] **Step 7: Commit**

```bash
git add spa/src/pages/quality/inspections/lot-review.tsx spa/src/pages/quality/inspections/lot-review.test.tsx spa/src/routes/qualityRoutes.tsx spa/src/pages/inventory/grn/detail.tsx
git commit -m "feat: task 9 — review a receipt's pending QC lines on one screen"
```

---

### Task 10: Collapse the GRN line's lot details

**Files:**
- Modify: `spa/src/pages/inventory/grn/create.tsx` (the secondary `<tr>` opening at line 320 and its close at line 410, plus new state and helpers)
- Test: `spa/src/pages/inventory/grn/create.test.tsx` (create)

**Interfaces:**
- Consumes: the existing `Line` interface, `items` state and `setItems` in `create.tsx`. No API or validation change.
- Produces: no exported interface — presentation only.

- [ ] **Step 1: Write the failing test**

Create `spa/src/pages/inventory/grn/create.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import CreateGrnPage from './create';
import { grnApi } from '@/api/inventory/grn';
import { warehouseApi } from '@/api/inventory/warehouse';

vi.mock('@/api/inventory/grn', () => ({
  grnApi: { receivablePurchaseOrders: vi.fn(), receivablePurchaseOrder: vi.fn(), create: vi.fn() },
}));
vi.mock('@/api/inventory/warehouse', () => ({ warehouseApi: { tree: vi.fn() } }));

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/inventory/grn/new?po_id=po-1']}>
        <CreateGrnPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('CreateGrnPage lot details', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(grnApi.receivablePurchaseOrders).mockResolvedValue({ data: [] } as never);
    vi.mocked(grnApi.receivablePurchaseOrder).mockResolvedValue({
      id: 'po-1',
      po_number: 'PO-202609-0001',
      items: [
        {
          id: 'line-1',
          item: { id: 'item-1', code: 'RES-001', name: 'ABS Resin' },
          quantity: '2000',
          quantity_remaining: '2000',
          unit_price: '10.00',
          delivered_unit_cost: '10.00',
        },
      ],
    } as never);
    vi.mocked(warehouseApi.tree).mockResolvedValue([] as never);
  });

  it('hides the optional lot fields until the line asks for them', async () => {
    renderPage();

    expect(await screen.findByLabelText('Receive quantity')).toBeInTheDocument();
    expect(screen.queryByLabelText('Lot number')).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /lot details/i }));

    expect(screen.getByLabelText('Lot number')).toBeInTheDocument();
    expect(screen.getByLabelText('Expiry')).toBeInTheDocument();
  });
});
```

`Input` wires its visible `label` to the control it renders, so `getByLabelText` resolves. Read lines 320-410 first and use the exact label strings found there for the two assertions.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd spa && npx vitest run src/pages/inventory/grn/create.test.tsx
```
Expected: FAIL — "Lot number" is present before any click.

- [ ] **Step 3: Add the disclosure state and helpers**

In `spa/src/pages/inventory/grn/create.tsx`, add to the imports:

```tsx
import { LuChevronDown, LuChevronRight } from '@/lib/icons';
```

`Fragment` is already imported (the existing rows are wrapped in `<Fragment>`). Add beside the other `useState` calls:

```tsx
  const [expandedLots, setExpandedLots] = useState<Record<number, boolean>>({});
```

and after `validate`:

```tsx
  const lotDetailKeys = [
    'received_uom_code',
    'lot_number',
    'supplier_lot_reference',
    'expiry_date',
    'moisture_percentage',
    'coa_document_path',
  ] as const;

  const filledLotDetails = (line: Line): number =>
    lotDetailKeys.filter((key) => line[key].trim() !== '').length;

  const toggleLot = (index: number) => setExpandedLots((s) => ({ ...s, [index]: !s[index] }));

  const setAllLots = (open: boolean) =>
    setExpandedLots(Object.fromEntries(items.map((_, index) => [index, open])));

  /** Copies this line's lot details onto every line below it. */
  const fillDownLot = (index: number) => {
    const source = items[index];
    setItems(
      items.map((line, k) => {
        if (k <= index) return line;
        const next = { ...line };
        for (const key of lotDetailKeys) next[key] = source[key];
        return next;
      }),
    );
  };
```

- [ ] **Step 4: Gate the secondary row behind the disclosure**

Replace the secondary row's opening

```tsx
                      <tr className="border-b border-subtle bg-subtle/40">
                        <Td colSpan={7}>
                          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2">
```

with a disclosure row followed by the conditional original:

```tsx
                      <tr className="border-b border-subtle">
                        <Td colSpan={7} className="py-1">
                          <div className="flex items-center gap-2">
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              icon={expandedLots[i] ? <LuChevronDown size={12} /> : <LuChevronRight size={12} />}
                              onClick={() => toggleLot(i)}
                            >
                              Lot details
                              {filledLotDetails(line) > 0 && ` · ${filledLotDetails(line)}`}
                            </Button>
                            {filledLotDetails(line) > 0 && (
                              <Button type="button" variant="ghost" size="sm" onClick={() => fillDownLot(i)}>
                                Fill down
                              </Button>
                            )}
                          </div>
                        </Td>
                      </tr>
                      {expandedLots[i] && (
                      <tr className="border-b border-subtle bg-subtle/40">
                        <Td colSpan={7}>
                          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2">
```

and close the conditional after that row's closing `</tr>`, replacing

```tsx
                          </div>
                        </Td>
                      </tr>
                    </Fragment>
                  ))}
```

with

```tsx
                          </div>
                        </Td>
                      </tr>
                      )}
                    </Fragment>
                  ))}
```

Then add a bulk control directly above the line table (immediately before the `<table className={tableCls}>` that renders the lines):

```tsx
              <div className="flex justify-end gap-2 mb-2">
                <Button type="button" variant="ghost" size="sm" onClick={() => setAllLots(true)}>
                  Show all lot details
                </Button>
                <Button type="button" variant="ghost" size="sm" onClick={() => setAllLots(false)}>
                  Hide all lot details
                </Button>
              </div>
```

- [ ] **Step 5: Run the checks**

```bash
cd spa && npx tsc --noEmit && npx vitest run src/pages/inventory/grn/create.test.tsx
```
Expected: `tsc` clean, test passes.

- [ ] **Step 6: Confirm the submitted payload is unchanged**

```bash
grep -n "lot_number: i.lot_number" spa/src/pages/inventory/grn/create.tsx
```
Expected: one hit — all six fields are still sent, so this change is presentation only.

- [ ] **Step 7: Commit**

```bash
git add spa/src/pages/inventory/grn/create.tsx spa/src/pages/inventory/grn/create.test.tsx
git commit -m "feat: task 10 — collapse the GRN line lot details behind a disclosure"
```

---

## Final verification

- [ ] **Full API suite** (expect ~1900+ tests, 0 failures; run only when no other suite is active):

```bash
docker compose exec -T api php artisan test
```

- [ ] **SPA checks:**

```bash
cd spa && npx tsc --noEmit && npx vitest run && npm run audit:tokens
```

- [ ] **Manual smoke, in order:**
  1. Receive a PO of 2,000 pieces → the GRN's lot fields are collapsed per line, and open only when asked.
  2. Open the receipt's QC line → the panel shows a checklist, one defect-count field, and 5 piece columns per dimension — not 125.
  3. Submit with 0 defects → the inspection passes, or routes to `awaiting_review` under maker-checker.
  4. Generate the Certificate of Conformance for that lot → it is issued, and it declares the full AQL sample.
  5. Review a multi-line receipt through `/quality/inspections/lot-review/<grn>` → one screen, per-line outcomes, and a refused line reports its own server message.
