# Punch Rules Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the Time In/Out business-rule gaps: early time-out, late time-in, rest-day/holiday work without authorization, ignored `working_days`, and unnoticed open rows.

**Architecture:** New guards live in `PunchService` (shift-instance helper, non-working-day authorization via an Approved `OvertimeRequest`, late-in cutoff, minimum-work/early-out with a shared `earliestOut()`); `ShiftAssignmentService` gains `assignmentFor()`/`isWorkingDay()` which `DTRComputationService` uses instead of the hard-coded Sunday; the daily missing-time-out command also fans out to department heads; payroll gets a non-blocking `OpenAttendance` anomaly; the SPA card shows the earliest allowed time-out and recovery hints.

**Tech Stack:** Laravel 11 / PHP 8.3 / PostgreSQL 16 / PHPUnit; React 18 + TypeScript + Vitest.

**Spec:** `docs/superpowers/specs/2026-10-07-punch-rules-hardening-design.md` (read it first; it is the binding authority).

## Global Constraints

- `declare(strict_types=1);` in every PHP file; every rejection is a `PunchRejectedException(code, message)` thrown INSIDE the `DB::transaction()` in `PunchService::punch()` so a rejection writes nothing.
- New rejection codes: `work_not_authorized`, `too_late_in`, `min_work`, `too_early_out`.
- New settings (migration `0572_seed_punch_rule_settings`): `attendance.punch_early_out_grace_minutes`=15 (integer 0–120), `attendance.punch_min_work_minutes`=30 (integer 0–240), `attendance.punch_late_in_cutoff_minutes`=240 (integer 30–720). Each needs the migration, a `SettingsSeeder` row AND an `UpdateSettingRequest` rule.
- Behaviour rules (spec decisions 1–7): OUT earlier than `shift end − grace` refused; OUT earlier than `latest IN punch + min work` refused; the FIRST IN of the day later than `shift start + cutoff` refused (later IN cycles and authorized non-working-day work exempt); IN on a non-working day or any holiday needs an Approved `OvertimeRequest` for the shift's START date; a day is a working day per the assignment `working_days` (ISO 1–7) and when null/missing/no assignment: Monday–Saturday work, Sunday rest; authorized non-working-day OUT only needs the minimum-work time.
- Pay amounts, premium rates and the overtime approval flow do not change.
- **`working_days` is uncommitted user work.** The column migration `2026_10_05_000010_add_working_days_to_employee_shift_assignments`, the model's `working_days` fillable/cast and `ShiftAssignmentService::configureWorkingDays/assertWorkingDays` are NOT in HEAD. Do not commit them. Code must degrade to the Sunday default when the attribute is missing/null/an un-cast JSON string. Tests needing a non-null `working_days` call `$this->assignShift(..., $days)` (Task 1), which skips when the column is absent.
- Migration number: confirm `ls api/database/migrations | grep -E '^05' | sort | tail -3` ends at `0571_…`; use `0572_`. It only inserts settings, so the plain numeric prefix is correct.
- Tests: use `'XX-T-'.substr(uniqid(), -5)` for varchar seeds; users via `User::factory()->create(['employee_id' => …, 'role_id' => Role::query()->where('slug', X)->value('id')])`, never `assignRole()`. 2026-10-07 is a Wednesday, 2026-10-10 Saturday, 2026-10-11 Sunday.
- Run PHP tests on an isolated database: `docker compose exec -T db psql -U ogami -d postgres -c "CREATE DATABASE ogami_test_rules OWNER ogami;"` then `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan migrate --force`, and prefix every run with `-e DB_DATABASE=ogami_test_rules`. Never use `ogami_test`.
- **Staging (the working tree has large unrelated uncommitted user changes).** Never `git add -A`, `git add .`, `git add <dir>`, `git commit -a`, stash, reset or reformat. Files that are DIRTY in the working tree (stage via a HEAD blob + only your hunks): `api/database/seeders/SettingsSeeder.php`, `api/app/Modules/Admin/Requests/UpdateSettingRequest.php`, `api/app/Modules/Attendance/Services/ShiftAssignmentService.php`, `api/app/Modules/Payroll/Services/PayrollAnomalyService.php`, `api/app/Modules/Payroll/Services/PayrollPeriodService.php`, `spa/src/types/attendance.ts`, `spa/src/types/payroll.ts`, `spa/src/components/payroll/AnomalyReviewPanel.tsx`. Verify with `git status --short <file>` before editing; if a file turns out clean, edit and `git add` it normally. Blob procedure: edit the working-tree file, run tests; then `git show HEAD:<file> > /tmp/blob`, apply the SAME edit to the blob with a small python `str.replace` (assert the anchor occurs exactly once), `h=$(git hash-object -w /tmp/blob); git update-index --cacheinfo 100644,$h,<file>`, then check `git diff --cached -- <file> | grep '^[+-]' | grep -v '^+++\|^---'` shows ONLY your lines. After committing, the committed tree must still type-check/pass: for SPA files run `rm -rf /tmp/spa_chk && mkdir /tmp/spa_chk && git archive HEAD spa | tar -x -C /tmp/spa_chk && ln -s /home/ec2-user/kwatog/spa/node_modules /tmp/spa_chk/spa/node_modules && (cd /tmp/spa_chk/spa && npx tsc --noEmit)`.
- Commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

- A night shift that starts Saturday and ends Sunday is judged by its START day (Task 4 test; Friday→Saturday for a Monday–Friday worker is the guarded variant).
- An overtime request approved AFTER a refused punch makes the retry succeed (Task 4 test).
- A non-manual row that carries a stale `is_rest_day = true` is recomputed as a working day (Task 3 test).
- A post-shift overtime second cycle is exempt from the late-in cutoff and early-out rule but still bound by minimum work (Task 4 tests).
- A department with two department heads notifies both once; a department with none is reported, not failed (Task 5 tests).

## File structure

- Create: `api/database/migrations/0572_seed_punch_rule_settings.php`
- Create: `api/tests/Feature/Attendance/PunchRuleSettingsTest.php`, `ShiftAssignmentWorkingDaysTest.php`, `DtrWorkingDaysTest.php`, `PunchRulesTest.php`, `api/tests/Feature/Payroll/OpenAttendanceAnomalyTest.php`
- Modify: `api/tests/Feature/Attendance/Concerns/PunchFixtures.php` (helpers), `PunchServiceTest.php` (one test), `PunchCommandsTest.php`
- Modify: `SettingsSeeder.php`*, `UpdateSettingRequest.php`*, `ShiftAssignmentService.php`*, `DTRComputationService.php`, `PunchService.php`, `NotifyMissingTimeOut.php`, `PayrollAnomalyType.php`, `PayrollAnomalyService.php`*, `PayrollPeriodService.php`*
- Modify (SPA): `spa/src/hooks/useTimeClock.ts`, `spa/src/components/self-service/TimeClockCard.tsx`, its test, `spa/src/types/attendance.ts`*, `spa/src/types/payroll.ts`*, `spa/src/components/payroll/AnomalyReviewPanel.tsx`*

(* = dirty in the working tree, blob staging.)

---

### Task 1: Settings + test fixtures

**Files:**
- Create: `api/database/migrations/0572_seed_punch_rule_settings.php`
- Modify: `api/database/seeders/SettingsSeeder.php` (after the `attendance.punch_late_minutes` row), `api/app/Modules/Admin/Requests/UpdateSettingRequest.php` (after the `attendance.punch_late_minutes` rule)
- Modify: `api/tests/Feature/Attendance/Concerns/PunchFixtures.php`
- Test: `api/tests/Feature/Attendance/PunchRuleSettingsTest.php`

**Interfaces:**
- Produces: the three settings (see Global Constraints); trait helpers `assignShift(Employee, Shift, ?array $workingDays = null): void`, `approveOvertime(Employee, string $date): OvertimeRequest`, `holiday(string $date, string $type = 'regular'): Holiday`.

- [ ] **Step 1: Write the failing test** `api/tests/Feature/Attendance/PunchRuleSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PunchRuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_punch_rule_settings_exist_with_their_defaults(): void
    {
        $s = app(SettingsService::class);
        $this->assertSame(15, $s->requiredInt('attendance.punch_early_out_grace_minutes'));
        $this->assertSame(30, $s->requiredInt('attendance.punch_min_work_minutes'));
        $this->assertSame(240, $s->requiredInt('attendance.punch_late_in_cutoff_minutes'));
    }

    public function test_admin_can_change_them_within_range_and_not_outside(): void
    {
        $admin = User::factory()->create(['role_id' => Role::query()->where('slug', 'system_admin')->value('id')]);

        $this->actingAs($admin)->putJson('/api/v1/admin/settings/attendance.punch_early_out_grace_minutes', ['value' => 20])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/attendance.punch_early_out_grace_minutes', ['value' => 500])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/attendance.punch_min_work_minutes', ['value' => 241])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/attendance.punch_late_in_cutoff_minutes', ['value' => 10])->assertStatus(422);
    }
}
```
If the settings route differs, copy the exact URL/verb from `api/tests/Feature/Attendance/PunchSettingsTest.php`.

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter=PunchRuleSettingsTest`
Expected: FAIL (settings missing).

- [ ] **Step 3: Implement**

`api/database/migrations/0572_seed_punch_rule_settings.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTINGS = [
        ['attendance.punch_early_out_grace_minutes', 15, 'Punch: early time-out grace (min)', 'How many minutes before shift end an employee may time out.'],
        ['attendance.punch_min_work_minutes', 30, 'Punch: minimum work before time-out (min)', 'Minimum minutes between the latest time-in punch and a time-out.'],
        ['attendance.punch_late_in_cutoff_minutes', 240, 'Punch: late time-in cutoff (min)', 'The first time-in of the day is refused later than this many minutes after shift start.'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'attendance',
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::SETTINGS, 0))->delete();
    }
};
```

`SettingsSeeder.php` — insert directly after the row whose key is `attendance.punch_late_minutes`:

```php
            ['key' => 'attendance.punch_early_out_grace_minutes', 'value' => 15, 'group' => 'attendance', 'label' => 'Punch: early time-out grace (min)', 'description' => 'How many minutes before shift end an employee may time out.'],
            ['key' => 'attendance.punch_min_work_minutes', 'value' => 30, 'group' => 'attendance', 'label' => 'Punch: minimum work before time-out (min)', 'description' => 'Minimum minutes between the latest time-in punch and a time-out.'],
            ['key' => 'attendance.punch_late_in_cutoff_minutes', 'value' => 240, 'group' => 'attendance', 'label' => 'Punch: late time-in cutoff (min)', 'description' => 'The first time-in of the day is refused later than this many minutes after shift start.'],
```

`UpdateSettingRequest.php` — insert directly after the line `'attendance.punch_late_minutes' => …`:

```php
            'attendance.punch_early_out_grace_minutes' => ['value' => ['required', 'integer', 'between:0,120']],
            'attendance.punch_min_work_minutes' => ['value' => ['required', 'integer', 'between:0,240']],
            'attendance.punch_late_in_cutoff_minutes' => ['value' => ['required', 'integer', 'between:30,720']],
```

`PunchFixtures.php` — add imports `use App\Modules\Attendance\Enums\OvertimeStatus; use App\Modules\Attendance\Models\Holiday; use App\Modules\Attendance\Models\OvertimeRequest; use Illuminate\Support\Facades\Cache; use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema; use App\Modules\Attendance\Models\Shift;` (Shift/Employee already imported — do not duplicate) and these methods in the trait:

```php
    /** Assign $shift to $employee; $workingDays needs the working_days column (uncommitted user work), else the test skips. */
    protected function assignShift(Employee $employee, Shift $shift, ?array $workingDays = null): void
    {
        $hasColumn = Schema::hasColumn('employee_shift_assignments', 'working_days');
        if ($workingDays !== null && ! $hasColumn) {
            $this->markTestSkipped('working_days column is not committed yet; commit the working_days migration first.');
        }

        $row = [
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_date' => '2026-01-01',
            'end_date' => null,
            'created_at' => now(),
        ];
        if ($hasColumn) {
            $row['working_days'] = $workingDays === null ? null : json_encode($workingDays);
        }
        DB::table('employee_shift_assignments')->insert($row);
    }

    protected function approveOvertime(Employee $employee, string $date): OvertimeRequest
    {
        $ot = OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'hours_requested' => 2,
            'reason' => 'XX-T punch rules',
        ]);
        $ot->forceFill(['status' => OvertimeStatus::Approved->value])->save();

        return $ot;
    }

    protected function holiday(string $date, string $type = 'regular'): Holiday
    {
        $h = Holiday::create(['name' => 'XX-T-'.substr(uniqid(), -5), 'date' => $date, 'type' => $type, 'is_recurring' => false]);
        Cache::put('holidays:cache_version', (int) Cache::get('holidays:cache_version', 0) + 1);

        return $h;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter='PunchRuleSettingsTest|PunchSettingsTest'`
Expected: PASS.

- [ ] **Step 5: Commit** (SettingsSeeder and UpdateSettingRequest are dirty → blob method; the other files are new/clean):

```bash
git add api/database/migrations/0572_seed_punch_rule_settings.php api/tests/Feature/Attendance/PunchRuleSettingsTest.php api/tests/Feature/Attendance/Concerns/PunchFixtures.php
# blob-stage SettingsSeeder.php and UpdateSettingRequest.php per Global Constraints, then:
git commit -m "feat: punch rules — settings for early-out, minimum work and late-in cutoff

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `ShiftAssignmentService::assignmentFor` / `isWorkingDay`

**Files:**
- Modify: `api/app/Modules/Attendance/Services/ShiftAssignmentService.php` (DIRTY — blob staging; replace `current()`)
- Test: `api/tests/Feature/Attendance/ShiftAssignmentWorkingDaysTest.php`

**Interfaces:**
- Consumes: `PunchFixtures::assignShift`, `shift()`, `worker()`.
- Produces: `assignmentFor(Employee $employee, CarbonInterface $date): ?EmployeeShiftAssignment`; `isWorkingDay(Employee $employee, CarbonInterface $date): bool`; `current()` unchanged in signature.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Services\ShiftAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class ShiftAssignmentWorkingDaysTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private function svc(): ShiftAssignmentService
    {
        return app(ShiftAssignmentService::class);
    }

    public function test_no_assignment_means_monday_to_saturday_work_and_sunday_rest(): void
    {
        [$emp] = $this->worker();

        $this->assertTrue($this->svc()->isWorkingDay($emp, CarbonImmutable::parse('2026-10-10'))); // Saturday
        $this->assertFalse($this->svc()->isWorkingDay($emp, CarbonImmutable::parse('2026-10-11'))); // Sunday
    }

    public function test_null_working_days_keeps_the_sunday_default(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, $this->shift(), null);

        $this->assertTrue($this->svc()->isWorkingDay($emp, CarbonImmutable::parse('2026-10-07')));
        $this->assertFalse($this->svc()->isWorkingDay($emp, CarbonImmutable::parse('2026-10-11')));
    }

    public function test_configured_working_days_are_followed(): void
    {
        [$mf] = $this->worker();
        [$st] = $this->worker();
        $shift = $this->shift();
        $this->assignShift($mf, $shift, [1, 2, 3, 4, 5]);   // Monday–Friday
        $this->assignShift($st, $shift, [7, 1, 2, 3, 4]);   // Sunday–Thursday

        $this->assertFalse($this->svc()->isWorkingDay($mf, CarbonImmutable::parse('2026-10-10'))); // Saturday
        $this->assertTrue($this->svc()->isWorkingDay($mf, CarbonImmutable::parse('2026-10-09')));  // Friday
        $this->assertTrue($this->svc()->isWorkingDay($st, CarbonImmutable::parse('2026-10-11')));  // Sunday
        $this->assertFalse($this->svc()->isWorkingDay($st, CarbonImmutable::parse('2026-10-09'))); // Friday
    }

    public function test_assignment_for_respects_the_date_range_and_current_still_returns_the_shift(): void
    {
        [$emp] = $this->worker();
        $shift = $this->shift();
        $this->assignShift($emp, $shift, null);

        $this->assertNotNull($this->svc()->assignmentFor($emp, CarbonImmutable::parse('2026-10-07')));
        $this->assertNull($this->svc()->assignmentFor($emp, CarbonImmutable::parse('2025-12-31')));
        $this->assertSame($shift->id, $this->svc()->current($emp, CarbonImmutable::parse('2026-10-07'))?->id);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter=ShiftAssignmentWorkingDaysTest`
Expected: FAIL (`isWorkingDay` / `assignmentFor` undefined).

- [ ] **Step 3: Implement** — replace the whole `current()` method (it is identical in HEAD and the working tree) with:

```php
    /**
     * The assignment whose [effective_date, end_date|∞] interval contains $date
     * (newest effective_date wins), or null.
     */
    public function assignmentFor(Employee $employee, CarbonInterface $date): ?EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_date', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_date')
            ->first();
    }

    /** Resolve the current shift for an employee on a given date. */
    public function current(Employee $employee, CarbonInterface $date): ?Shift
    {
        return $this->assignmentFor($employee, $date)?->shift()->first();
    }

    /**
     * Whether $date is a working day for $employee. Follows the assignment's
     * `working_days` (ISO weekdays 1–7). A missing attribute, null, empty value or
     * un-cast JSON string that cannot be read falls back to the long-standing
     * default: Monday–Saturday work, Sunday rest.
     */
    public function isWorkingDay(Employee $employee, CarbonInterface $date): bool
    {
        $days = $this->assignmentFor($employee, $date)?->working_days;
        if (is_string($days)) {
            $days = json_decode($days, true);
        }

        $iso = $date->dayOfWeekIso;

        return is_array($days) && $days !== []
            ? in_array($iso, $days, true)
            : $iso !== 7;
    }
```
`EmployeeShiftAssignment` is already imported in that file (used by the old `current()`).

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter='ShiftAssignmentWorkingDaysTest|AttendanceHardeningTest'`
Expected: PASS (tests needing `working_days` skip if the column is absent).

- [ ] **Step 5: Commit** — `ShiftAssignmentService.php` is dirty: build the staged blob from HEAD by replacing HEAD's `current()` method with the three methods above (assert the old method text occurs exactly once). Add the test by explicit path.

```bash
git commit -m "feat: punch rules — assignmentFor and isWorkingDay on ShiftAssignmentService

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: DTR rest day follows working days

**Files:**
- Modify: `api/app/Modules/Attendance/Services/DTRComputationService.php:62` (clean file)
- Test: `api/tests/Feature/Attendance/DtrWorkingDaysTest.php`

**Interfaces:**
- Consumes: `ShiftAssignmentService::isWorkingDay` (already injected as `$this->shiftAssignments`).
- Produces: `computeForRecord()` derives `is_rest_day` as `(is_manual_entry && is_rest_day) || ! isWorkingDay`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\DTRComputationService;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class DtrWorkingDaysTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private function compute(Employee $emp, string $date, array $attrs = []): Attendance
    {
        $a = new Attendance($attrs + [
            'employee_id' => $emp->id,
            'date' => $date,
            'time_in' => "{$date} 06:00:00",
            'time_out' => "{$date} 14:00:00",
        ]);

        return app(DTRComputationService::class)->computeForRecord($a);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift();
    }

    public function test_without_working_days_sunday_is_rest_and_saturday_is_not(): void
    {
        [$emp] = $this->worker();

        $this->assertFalse($this->compute($emp, '2026-10-10')->is_rest_day); // Saturday
        $this->assertTrue($this->compute($emp, '2026-10-11')->is_rest_day);  // Sunday
    }

    public function test_a_monday_to_friday_worker_has_saturday_as_a_rest_day(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, \App\Modules\Attendance\Models\Shift::query()->first(), [1, 2, 3, 4, 5]);

        $row = $this->compute($emp, '2026-10-10');

        $this->assertTrue($row->is_rest_day);
        $this->assertGreaterThan(1.0, (float) $row->day_type_rate);
    }

    public function test_a_sunday_to_thursday_worker_has_sunday_as_a_normal_day(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, \App\Modules\Attendance\Models\Shift::query()->first(), [7, 1, 2, 3, 4]);

        $this->assertFalse($this->compute($emp, '2026-10-11')->is_rest_day);
    }

    public function test_hr_manual_entry_keeps_its_explicit_rest_day_flag(): void
    {
        [$emp] = $this->worker();

        $row = $this->compute($emp, '2026-10-07', ['is_manual_entry' => true, 'is_rest_day' => true]);

        $this->assertTrue($row->is_rest_day);
    }

    public function test_a_non_manual_row_does_not_inherit_a_stale_rest_day_flag(): void
    {
        [$emp] = $this->worker();

        $row = $this->compute($emp, '2026-10-07', ['is_manual_entry' => false, 'is_rest_day' => true]);

        $this->assertFalse($row->is_rest_day);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter=DtrWorkingDaysTest`
Expected: FAIL (stale flag is sticky; Saturday/Sunday classified by the hard-coded Sunday).

- [ ] **Step 3: Implement** — in `DTRComputationService::computeForRecord`, replace

```php
        $isRestDay = (bool) $a->is_rest_day || $date->dayOfWeek === Carbon::SUNDAY;
```
with

```php
        // HR's manual entries keep their explicit flag; every other row follows the
        // employee's working days (null/unset keeps Monday–Saturday work, Sunday rest)
        // and no longer inherits a flag stored by an earlier computation.
        $isRestDay = ($a->is_manual_entry && (bool) $a->is_rest_day)
            || ! $this->shiftAssignments->isWorkingDay($a->employee ?? Employee::findOrFail($a->employee_id), $date);
```
Add `use App\Modules\HR\Models\Employee;` if not imported. If `Carbon` becomes unused in that file, leave the import only if still used elsewhere (`grep -n "Carbon::" DTRComputationService.php`).

- [ ] **Step 4: Run to verify it passes, plus the DTR/import/payroll suites**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter='DtrWorkingDaysTest|DTRComputationServiceTest|PairedCsvImportTest|RawPunchImportTest|ImportManualCorrectionGuardTest|PunchServiceTest'`
Expected: PASS. If a pre-existing DTR test relied on a stored `is_rest_day` on a non-manual row, report it and adapt the TEST to set `is_manual_entry => true`; do not change the rule.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Attendance/Services/DTRComputationService.php api/tests/Feature/Attendance/DtrWorkingDaysTest.php
git commit -m "feat: punch rules — DTR rest day follows employee working days

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: PunchService rules + status `earliest_out`

**Files:**
- Modify: `api/app/Modules/Attendance/Services/PunchService.php` (clean file)
- Modify: `api/tests/Feature/Attendance/PunchServiceTest.php` (replace one test)
- Test: `api/tests/Feature/Attendance/PunchRulesTest.php`

**Interfaces:**
- Consumes: `ShiftAssignmentService::isWorkingDay` (Task 2), `HolidayService::forDate(CarbonInterface): ?Holiday`, the three settings (Task 1), fixtures (Task 1).
- Produces: new rejection codes `work_not_authorized`, `too_late_in`, `min_work`, `too_early_out`; `status()` gains `earliest_out: ?string` (ISO-8601, only when `next_action` is `out`).

- [ ] **Step 1: Update the existing test that the new rules invalidate.** In `PunchServiceTest.php` replace `test_extra_in_after_out_keeps_first_in_and_last_out` with:

```php
    public function test_post_shift_second_cycle_keeps_first_in_and_last_out(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 14:00:00');
        $this->punch($emp);               // final out of the shift
        $this->at('2026-10-07 14:30:00');
        $in2 = $this->punch($emp);        // post-shift overtime cycle
        $this->assertSame(PunchDirection::In, $in2->direction);
        $this->at('2026-10-07 15:10:00');
        $this->punch($emp);               // overtime out

        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:00', $row->time_in->format('H:i'));
        $this->assertSame('15:10', $row->time_out->format('H:i'));
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(4, AttendancePunch::query()->count());
    }
```

- [ ] **Step 2: Write the failing tests** `api/tests/Feature/Attendance/PunchRulesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Exceptions\PunchRejectedException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchRulesTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private function punch(Employee $employee)
    {
        return app(PunchService::class)->punch($employee, 14.2860, 120.9345, 20.0, '127.0.0.1', 'phpunit', null);
    }

    private function assertRejected(string $code, callable $fn): PunchRejectedException
    {
        try {
            $fn();
            $this->fail("Expected PunchRejectedException [{$code}]");
        } catch (PunchRejectedException $e) {
            $this->assertSame($code, $e->errorCode());

            return $e;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift(); // 06:00–14:00 default shift
    }

    public function test_early_out_is_refused_until_shift_end_minus_grace(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);

        $this->at('2026-10-07 13:44:00');
        $e = $this->assertRejected('too_early_out', fn () => $this->punch($emp));
        $this->assertStringContainsString('1:45 PM', $e->getMessage());

        $this->at('2026-10-07 13:45:00');
        $this->assertSame(PunchDirection::Out, $this->punch($emp)->direction);
    }

    public function test_a_sixty_one_second_in_out_is_refused(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 06:01:01');

        $this->assertRejected('too_early_out', fn () => $this->punch($emp));
        $this->assertNull(Attendance::query()->where('employee_id', $emp->id)->firstOrFail()->time_out);
    }

    public function test_a_post_shift_cycle_still_needs_the_minimum_work_time(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 14:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 14:10:00');
        $this->punch($emp); // second IN is exempt from the late-in cutoff

        $this->at('2026-10-07 14:20:00');
        $e = $this->assertRejected('min_work', fn () => $this->punch($emp));
        $this->assertStringContainsString('2:40 PM', $e->getMessage());

        $this->at('2026-10-07 14:40:00');
        $this->assertSame(PunchDirection::Out, $this->punch($emp)->direction);
    }

    public function test_the_first_in_after_the_cutoff_is_refused(): void
    {
        [$late] = $this->worker();
        [$ok] = $this->worker();

        $this->at('2026-10-07 10:01:00'); // 06:00 + 240 min = 10:00
        $this->assertRejected('too_late_in', fn () => $this->punch($late));
        $this->assertSame(0, Attendance::query()->where('employee_id', $late->id)->count());

        $this->at('2026-10-07 10:00:00');
        $this->assertSame(PunchDirection::In, $this->punch($ok)->direction);
    }

    public function test_sunday_work_needs_an_approved_overtime_request_and_a_retry_then_succeeds(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-11 06:05:00'); // Sunday
        $this->assertRejected('work_not_authorized', fn () => $this->punch($emp));

        $ot = $this->approveOvertime($emp, '2026-10-11');
        $ot->forceFill(['status' => 'pending'])->save(); // pending is not authorization
        $this->at('2026-10-11 06:07:00');
        $this->assertRejected('work_not_authorized', fn () => $this->punch($emp));

        $ot->forceFill(['status' => 'approved'])->save();
        $this->at('2026-10-11 06:09:00');
        $this->assertSame(PunchDirection::In, $this->punch($emp)->direction);
    }

    public function test_a_holiday_on_a_working_day_needs_authorization(): void
    {
        [$emp] = $this->worker();
        $this->holiday('2026-10-07', 'special_non_working'); // Wednesday
        $this->at('2026-10-07 06:05:00');
        $this->assertRejected('work_not_authorized', fn () => $this->punch($emp));

        $this->approveOvertime($emp, '2026-10-07');
        $this->at('2026-10-07 06:07:00');
        $this->assertSame(PunchDirection::In, $this->punch($emp)->direction);
    }

    public function test_authorized_rest_day_work_only_needs_the_minimum_work_time(): void
    {
        [$emp] = $this->worker();
        $this->approveOvertime($emp, '2026-10-11');
        $this->at('2026-10-11 06:05:00');
        $this->punch($emp);

        $this->at('2026-10-11 06:20:00');
        $this->assertRejected('min_work', fn () => $this->punch($emp));

        $this->at('2026-10-11 06:40:00'); // long before shift end − grace, but past 30 min
        $this->assertSame(PunchDirection::Out, $this->punch($emp)->direction);
    }

    public function test_a_night_shift_starting_saturday_is_judged_by_its_start_day(): void
    {
        $this->shift('22:00:00', '06:00:00', true);
        [$emp] = $this->worker();

        $this->at('2026-10-10 22:05:00'); // Saturday (working day by default)
        $this->assertSame(PunchDirection::In, $this->punch($emp)->direction);

        $this->at('2026-10-11 06:02:00'); // Sunday morning: row date is Saturday
        $this->assertSame(PunchDirection::Out, $this->punch($emp)->direction);
        $this->assertSame('2026-10-10', Attendance::query()->where('employee_id', $emp->id)->firstOrFail()->date->toDateString());
    }

    public function test_a_monday_to_friday_worker_cannot_punch_on_saturday_without_authorization(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, \App\Modules\Attendance\Models\Shift::query()->first(), [1, 2, 3, 4, 5]);
        $this->at('2026-10-10 06:05:00');

        $this->assertRejected('work_not_authorized', fn () => $this->punch($emp));
    }

    public function test_a_sunday_to_thursday_worker_can_punch_on_sunday_as_a_normal_day(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, \App\Modules\Attendance\Models\Shift::query()->first(), [7, 1, 2, 3, 4]);
        $this->at('2026-10-11 06:05:00');

        $this->assertSame(PunchDirection::In, $this->punch($emp)->direction);
    }

    public function test_status_reports_the_earliest_out_the_check_will_enforce(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->assertNull(app(PunchService::class)->status($emp)['earliest_out']);

        $this->punch($emp);
        $status = app(PunchService::class)->status($emp);

        $this->assertSame('13:45', CarbonImmutable::parse($status['earliest_out'])->format('H:i'));
    }
}
```

- [ ] **Step 3: Run to verify they fail**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter='PunchRulesTest|PunchServiceTest'`
Expected: FAIL (rules missing; `earliest_out` key undefined).

- [ ] **Step 4: Implement** in `PunchService.php`.

Imports to add: `use App\Modules\Attendance\Enums\OvertimeStatus; use App\Modules\Attendance\Models\OvertimeRequest;`. Constructor — add `private readonly HolidayService $holidays,` as the LAST parameter (the class is autowired; `HolidayService` is in the same namespace).

(a) Extract the shift-instance builder and use it in `windowDate`:

```php
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} shift start/end for the shift instance beginning on $day */
    private function shiftInstance(Shift $shift, CarbonImmutable $day): array
    {
        $start = $day->setTimeFromTimeString((string) $shift->start_time);
        $end = $day->setTimeFromTimeString((string) $shift->end_time);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }
```
In `windowDate()` replace the three lines that build `$start`/`$end` (and the `if ($end->lessThanOrEqualTo($start))` block) with `[$start, $end] = $this->shiftInstance($shift, $day);`.

(b) New private helpers (place after `guardRow`):

```php
    private function isNonWorkingDay(Employee $employee, CarbonImmutable $day): bool
    {
        return ! $this->shifts->isWorkingDay($employee, $day) || $this->holidays->forDate($day) !== null;
    }

    private function hasApprovedOvertime(int $employeeId, CarbonImmutable $day): bool
    {
        return OvertimeRequest::query()
            ->where('employee_id', $employeeId)
            ->whereDate('date', $day->toDateString())
            ->where('status', OvertimeStatus::Approved->value)
            ->exists();
    }

    private function assertNotTooLateIn(Shift $shift, CarbonImmutable $anchor, CarbonImmutable $now): void
    {
        $cutoff = $this->settings->requiredInt('attendance.punch_late_in_cutoff_minutes', 30, 720);
        [$start] = $this->shiftInstance($shift, $anchor);
        if ($now->greaterThan($start->addMinutes($cutoff))) {
            throw new PunchRejectedException('too_late_in', 'It is too late to time in for this shift. Ask HR to record your attendance.');
        }
    }

    /**
     * Earliest moment an OUT is allowed, and which rule sets it.
     * Authorized non-working-day work is bounded only by minimum work; a normal
     * shift also cannot end before `shift end − grace`. The minimum counts from the
     * LATEST IN punch so a post-shift overtime cycle is measured from its own start.
     *
     * @return array{at: CarbonImmutable, reason: string}
     */
    private function earliestOut(Shift $shift, CarbonImmutable $rowDay, CarbonImmutable $latestIn, bool $nonWorking): array
    {
        $minWorkAt = $latestIn->addMinutes($this->settings->requiredInt('attendance.punch_min_work_minutes', 0, 240));
        if ($nonWorking) {
            return ['at' => $minWorkAt, 'reason' => 'min_work'];
        }

        [, $end] = $this->shiftInstance($shift, $rowDay);
        $shiftAt = $end->subMinutes($this->settings->requiredInt('attendance.punch_early_out_grace_minutes', 0, 120));

        return $shiftAt->greaterThan($minWorkAt)
            ? ['at' => $shiftAt, 'reason' => 'too_early_out']
            : ['at' => $minWorkAt, 'reason' => 'min_work'];
    }
```

(c) In `punch()`, directly AFTER the `if ($row !== null) { $this->guardRow($row); } elseif ($open) { … }` block and BEFORE `$date = …`, add:

```php
            $nonWorking = $this->isNonWorkingDay($employee, $anchor);
            if ($direction === PunchDirection::In) {
                if ($nonWorking && ! $this->hasApprovedOvertime($employee->id, $anchor)) {
                    throw new PunchRejectedException('work_not_authorized', 'Rest-day and holiday work needs an approved overtime request. File one, then try again once it is approved.');
                }
                if (! $nonWorking && ($row === null || $row->time_in === null)) {
                    $this->assertNotTooLateIn($shift, $anchor, $now);
                }
            }
```

(d) In the `else` (OUT) branch, directly after the `out_before_in` check, add:

```php
                $rowDay = CarbonImmutable::parse($a->date->toDateString());
                $limit = $this->earliestOut($shift, $rowDay, $last->punched_at->toImmutable(), $this->isNonWorkingDay($employee, $rowDay));
                if ($now->lessThan($limit['at'])) {
                    $time = $limit['at']->format('g:i A');
                    throw new PunchRejectedException(
                        $limit['reason'],
                        $limit['reason'] === 'min_work'
                            ? "Wait until {$time} to time out (minimum work time)."
                            : "You can time out from {$time}. For an earlier exit, ask HR to record it.",
                    );
                }
```
(`$last` is non-null whenever `$direction` is Out because `$open` requires it. If PHPStan/IDE complains, add `assert($last !== null);`.)

(e) In `status()`, add the key. Update the docblock return type with `earliest_out:?string`, and before the `return [...]` compute:

```php
        $earliestOut = null;
        if ($open && $row !== null && $shift !== null) {
            $rowDay = CarbonImmutable::parse($row->date->toDateString());
            $earliestOut = $this->earliestOut($shift, $rowDay, $last->punched_at->toImmutable(), $this->isNonWorkingDay($employee, $rowDay))['at']->toIso8601String();
        }
```
and add `'earliest_out' => $earliestOut,` to the returned array.

- [ ] **Step 5: Run to verify they pass, plus every punch/attendance suite**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter='Punch|Attendance|ShiftAssignment|DtrWorkingDays'`
Expected: PASS. A failing legacy test that punches a lunch OUT, or an IN more than 4 h after shift start, is an old-rule fixture: change its times, never the rule, and list each in the report.

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Attendance/Services/PunchService.php api/tests/Feature/Attendance/PunchRulesTest.php api/tests/Feature/Attendance/PunchServiceTest.php
git commit -m "feat: punch rules — early-out, minimum work, late-in cutoff and non-working-day authorization

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Department-head fan-out for missing time-outs

**Files:**
- Modify: `api/app/Console/Commands/NotifyMissingTimeOut.php` (clean)
- Modify: `api/tests/Feature/Attendance/PunchCommandsTest.php`

**Interfaces:**
- Consumes: `NotificationService::send(User|Collection|array $recipients, string $type, array $data, ?string $dedupeKey = null)`.
- Produces: per-department-head notifications of type `attendance.missing_time_out`, dedupe key `attendance.missing_time_out:{date}:{userId}`; exit code FAILURE if the HR recipient list is empty OR any send throws.

- [ ] **Step 1: Add failing tests** to `PunchCommandsTest.php` (reuse its imports; add `use Illuminate\Support\Facades\DB; use App\Modules\HR\Models\Department;` if missing):

```php
    private function departmentHead(Department $dept): User
    {
        $emp = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => $dept->id]);

        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $emp->id,
            'is_active' => true,
        ]);
    }

    private function missingTimeoutCount(): int
    {
        return DB::table('notifications')->where('type', 'attendance.missing_time_out')->count();
    }

    public function test_each_department_head_is_told_about_their_own_departments_open_rows(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id'), 'is_active' => true]);
        $dept = Department::factory()->create();
        $this->departmentHead($dept);
        $this->departmentHead($dept); // two heads: both notified
        $emp = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => $dept->id]);
        $this->at('2026-10-08 06:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->artisan('attendance:notify-missing-timeout')->assertSuccessful();

        $this->assertSame(3, $this->missingTimeoutCount()); // 1 HR + 2 heads

        $this->artisan('attendance:notify-missing-timeout')->assertSuccessful(); // rerun: deduped
        $this->assertSame(3, $this->missingTimeoutCount());
    }

    public function test_a_department_without_a_head_is_reported_but_is_not_a_failure(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id'), 'is_active' => true]);
        $emp = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => Department::factory()->create()->id]);
        $this->at('2026-10-08 06:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->artisan('attendance:notify-missing-timeout')
            ->expectsOutputToContain('no active department head')
            ->assertSuccessful();
        $this->assertSame(1, $this->missingTimeoutCount()); // HR only
    }
```
If `Department::factory()` does not exist, create with `Department::create(['name' => 'XX-T '.substr(uniqid(), -4), 'code' => 'XT-'.substr(uniqid(), -4)])` (as other tests in this directory do).

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter=PunchCommandsTest`
Expected: FAIL (only HR notified).

- [ ] **Step 3: Implement** — replace the body of `handle()` after the HR-recipient check so the command is:

```php
    public function handle(NotificationService $notifications): int
    {
        $yesterday = now()->subDay();
        $open = Attendance::query()
            ->with('employee.department')
            ->whereNotNull('time_in')->whereNull('time_out')
            ->whereDate('date', $yesterday->toDateString())
            ->get();
        $count = $open->count();

        if ($count === 0) {
            $this->info('No open attendance rows for yesterday.');

            return self::SUCCESS;
        }

        $hr = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'hr_officer'))
            ->where('is_active', true)
            ->get();

        if ($hr->isEmpty()) {
            $this->error("No active hr_officer to notify about {$count} open attendance row(s).");
            Log::error('attendance:notify-missing-timeout has no recipient', ['open_count' => $count]);

            return self::FAILURE;
        }

        $label = $yesterday->format('M j, Y');
        $failed = 0;
        $sent = 0;

        try {
            $notifications->send($hr, 'attendance.missing_time_out', [
                'title' => 'Missing time-out',
                'message' => "{$count} employee(s) have no time out for {$label}.",
                'link_to' => '/hr/attendance?missing_time_out=1',
                'entity_type' => 'attendance',
            ], 'attendance.missing_time_out:'.$yesterday->toDateString());
            $sent++;
        } catch (Throwable $e) {
            $failed++;
            Log::error('attendance:notify-missing-timeout HR send failed', ['error' => $e->getMessage()]);
            $this->error('Could not notify HR: '.$e->getMessage());
        }

        foreach ($open->groupBy(fn (Attendance $a) => $a->employee?->department_id) as $departmentId => $rows) {
            if (! $departmentId) {
                continue;
            }
            $heads = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'department_head'))
                ->where('is_active', true)
                ->whereHas('employee', fn ($q) => $q->where('department_id', $departmentId))
                ->get();
            $department = $rows->first()->employee?->department?->name ?? "department {$departmentId}";

            if ($heads->isEmpty()) {
                $this->warn("{$department}: no active department head to notify about {$rows->count()} open row(s).");

                continue;
            }

            foreach ($heads as $head) {
                try {
                    $notifications->send($head, 'attendance.missing_time_out', [
                        'title' => 'Missing time-out',
                        'message' => "{$rows->count()} employee(s) in {$department} have no time out for {$label}.",
                        'link_to' => '/hr/attendance?missing_time_out=1',
                        'entity_type' => 'attendance',
                    ], 'attendance.missing_time_out:'.$yesterday->toDateString().':'.$head->id);
                    $sent++;
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('attendance:notify-missing-timeout department head send failed', ['user_id' => $head->id, 'error' => $e->getMessage()]);
                    $this->error("Could not notify {$head->name}: ".$e->getMessage());
                }
            }
        }

        $this->info("Sent {$sent} notification(s) for {$count} open row(s); {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
```
Also update the class docblock/`$description` to say "HR and department heads". `User->name` exists (it is displayed in the SPA); if not use `$head->id`.

- [ ] **Step 4: Run to verify they pass**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter=PunchCommandsTest`
Expected: PASS (including the pre-existing quiet/failure tests). If the rerun assertion shows the dedupe key is global (3 rows → more or fewer), inspect `notifications.dedupe_key` uniqueness in the migration and report; do not change the key format without reporting.

- [ ] **Step 5: Commit**

```bash
git add api/app/Console/Commands/NotifyMissingTimeOut.php api/tests/Feature/Attendance/PunchCommandsTest.php
git commit -m "feat: punch rules — notify department heads of their open attendance rows

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `OpenAttendance` payroll anomaly (non-blocking)

**Files:**
- Modify: `api/app/Modules/Payroll/Enums/PayrollAnomalyType.php` (clean)
- Modify: `api/app/Modules/Payroll/Services/PayrollAnomalyService.php` (DIRTY — blob), `api/app/Modules/Payroll/Services/PayrollPeriodService.php` (DIRTY — blob)
- Modify: `spa/src/types/payroll.ts` (DIRTY), `spa/src/components/payroll/AnomalyReviewPanel.tsx` (DIRTY)
- Test: `api/tests/Feature/Payroll/OpenAttendanceAnomalyTest.php`

**Interfaces:**
- Produces: enum case `PayrollAnomalyType::OpenAttendance = 'open_attendance'`; flag `details = ['dates' => ['YYYY-MM-DD', …]]`; `PayrollAnomalyService::unresolvedCount()` and the finalize blocker in `PayrollPeriodService` ignore `open_attendance`.

- [ ] **Step 1: Write the failing test** (read `tests/Feature/Payroll/PayrollAnomalyPolicyTest.php` first for the exact way it sets period/payroll and calls detect, and mirror it):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollAnomalyType;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenAttendanceAnomalyTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_rows_raise_an_informational_flag_that_never_counts_as_unresolved(): void
    {
        $employee = Employee::factory()->create();
        $period = PayrollPeriod::factory()->create(['period_start' => '2026-01-01', 'period_end' => '2026-01-15']);
        $payroll = Payroll::factory()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);
        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-01-05', 'time_in' => '2026-01-05 06:00:00', 'status' => AttendanceStatus::Present->value]);
        Attendance::create(['employee_id' => $employee->id, 'date' => '2026-01-06', 'time_in' => '2026-01-06 06:00:00', 'time_out' => '2026-01-06 14:00:00', 'status' => AttendanceStatus::Present->value]);

        $svc = app(PayrollAnomalyService::class);
        $svc->detect($period);

        $flag = PayrollAnomalyFlag::query()->where('payroll_id', $payroll->id)->where('flag_type', PayrollAnomalyType::OpenAttendance->value)->firstOrFail();
        $this->assertSame(['2026-01-05'], $flag->details['dates']);

        // first_payroll is also raised for this brand-new employee and DOES block; the open-attendance flag does not.
        $total = PayrollAnomalyFlag::query()->where('payroll_period_id', $period->id)->count();
        $this->assertGreaterThan($svc->unresolvedCount($period->id), $total);
        $this->assertSame(
            PayrollAnomalyFlag::query()->where('payroll_period_id', $period->id)->where('flag_type', '!=', PayrollAnomalyType::OpenAttendance->value)->where('is_resolved', false)->count(),
            $svc->unresolvedCount($period->id),
        );
    }

    public function test_no_open_rows_means_no_flag(): void
    {
        $employee = Employee::factory()->create();
        $period = PayrollPeriod::factory()->create(['period_start' => '2026-01-01', 'period_end' => '2026-01-15']);
        $payroll = Payroll::factory()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id]);

        app(PayrollAnomalyService::class)->detect($period);

        $this->assertFalse(PayrollAnomalyFlag::query()->where('payroll_id', $payroll->id)->where('flag_type', 'open_attendance')->exists());
    }
}
```
Dates are in the past relative to "today" (2026-10-07 test clock not required: rows dated January are `< now()`).

- [ ] **Step 2: Run to verify it fails** — `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter=OpenAttendanceAnomalyTest` → FAIL (enum case missing).

- [ ] **Step 3: Implement**

`PayrollAnomalyType.php` — add `case OpenAttendance = 'open_attendance';` and `self::OpenAttendance => 'Open attendance (no time-out)',` in `label()`.

`PayrollAnomalyService.php` — in `detectForPayroll()` immediately before the final `return $created;` add:

```php
        // 5. Open attendance rows — informational only: it never blocks finalize
        // (see unresolvedCount() and PayrollPeriodService::finalize), it just tells
        // the reviewer that pay was computed with a day that has no time-out.
        $openDates = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$period->period_start, $period->period_end])
            ->whereNotNull('time_in')->whereNull('time_out')
            ->whereDate('date', '<', now()->toDateString())
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->all();
        if ($openDates !== []) {
            $created += $this->flag($payroll, $period, PayrollAnomalyType::OpenAttendance, ['dates' => $openDates]);
        }
```
and change `unresolvedCount()` to:

```php
    public function unresolvedCount(int $periodId): int
    {
        return PayrollAnomalyFlag::where('payroll_period_id', $periodId)
            ->where('is_resolved', false)
            ->where('flag_type', '!=', PayrollAnomalyType::OpenAttendance->value)
            ->count();
    }
```
`PayrollPeriodService.php` (~line 1392) — in the finalize blocker query add `->where('flag_type', '!=', \App\Modules\Payroll\Enums\PayrollAnomalyType::OpenAttendance->value)` after `->where('is_resolved', false)`. Then `grep -rn "is_resolved" api/app/Modules/Payroll` and apply the same exclusion at any OTHER place that decides whether a period may advance (report each site you find).

SPA: `spa/src/types/payroll.ts` — add `| 'open_attendance'` to the `PayrollAnomalyType` union; `AnomalyReviewPanel.tsx` — add `open_attendance: 'info',` to `TYPE_VARIANT`. Run `npx tsc --noEmit` in `spa/` (the `Record<PayrollAnomalyType, …>` is exhaustive, so it fails until both are done).

- [ ] **Step 4: Run to verify it passes** — `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter='OpenAttendanceAnomalyTest|PayrollAnomaly|PayrollPeriod'` and `cd spa && npx tsc --noEmit && npx vitest run src/components/payroll` → PASS.

- [ ] **Step 5: Commit** — enum + test by explicit path; `PayrollAnomalyService.php`, `PayrollPeriodService.php`, `payroll.ts`, `AnomalyReviewPanel.tsx` are DIRTY → blob staging (see Global Constraints), then the committed-tree `tsc` check for the two SPA files.

```bash
git commit -m "feat: punch rules — non-blocking open-attendance payroll anomaly

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: SPA — earliest-out hint and recovery hints

**Files:**
- Modify: `spa/src/hooks/useTimeClock.ts` (clean), `spa/src/components/self-service/TimeClockCard.tsx` (clean), `spa/src/components/self-service/TimeClockCard.test.tsx` (clean), `spa/src/types/attendance.ts` (DIRTY — blob)

**Interfaces:**
- Consumes: status payload `earliest_out?: string | null` (Task 4).
- Produces: `PunchStatus.earliest_out?: string | null`; `RECOVERY` entries for `work_not_authorized`, `too_late_in`, `min_work`, `too_early_out`.

- [ ] **Step 1: Write the failing tests** — append to `TimeClockCard.test.tsx` (it already has `status`, `renderCard`, `mockGeolocation`):

```tsx
  it('tells a clocked-in employee the earliest time they can time out', async () => {
    vi.mocked(punchApi.status).mockResolvedValue({
      ...status,
      next_action: 'out',
      punches: [{ direction: 'in', punched_at: '2026-10-07T06:02:00+08:00' }],
      earliest_out: '2099-01-01T13:45:00+08:00',
    });
    renderCard();

    expect(await screen.findByText(/you can time out from/i)).toBeInTheDocument();
  });

  it('shows what to do after each new refusal code', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.286, longitude: 120.9345, accuracy: 18 } } as GeolocationPosition));
    vi.mocked(punchApi.punch).mockRejectedValue({
      response: { status: 422, data: { message: 'You can time out from 1:45 PM.', code: 'too_early_out' } },
    });
    renderCard();

    fireEvent.click(await screen.findByRole('button', { name: /time in/i }));

    expect(await screen.findByText(/ask hr to record it/i)).toBeInTheDocument();
  });
```

- [ ] **Step 2: Run to verify they fail** — `cd spa && npx vitest run src/components/self-service/TimeClockCard.test.tsx` → FAIL.

- [ ] **Step 3: Implement**

`spa/src/types/attendance.ts` — in `PunchStatus` add `earliest_out?: string | null;` (blob-stage this dirty file with ONLY that line).

`useTimeClock.ts` — add to `RECOVERY`:

```ts
  work_not_authorized: 'File an overtime request for that day. Once it is approved you can time in.',
  too_late_in: 'Ask HR to record your attendance for today.',
  min_work: 'Wait until the time shown, then try again.',
  too_early_out: 'For an earlier exit, ask HR to record it.',
```

`TimeClockCard.tsx` — directly below the shift/“No shift assigned” paragraph (inside the left column's state block) add:

```tsx
              {clockedIn && data.earliest_out && new Date(data.earliest_out) > new Date() ? (
                <p className="mt-0.5 text-xs text-muted">
                  You can time out from{' '}
                  <span className="font-mono tabular-nums">{formatTime(data.earliest_out)}</span>.
                </p>
              ) : null}
```

- [ ] **Step 4: Run to verify they pass** — `cd spa && npx vitest run src/components src/hooks && npx tsc --noEmit && npm run audit:tokens && npx eslint src/hooks/useTimeClock.ts src/components/self-service/TimeClockCard.tsx` → PASS; then the committed-tree check from Global Constraints.

- [ ] **Step 5: Commit**

```bash
git add spa/src/hooks/useTimeClock.ts spa/src/components/self-service/TimeClockCard.tsx spa/src/components/self-service/TimeClockCard.test.tsx
# blob-stage spa/src/types/attendance.ts, then:
git commit -m "feat: punch rules — earliest time-out hint and recovery hints on the card

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Verification and handoff

- [ ] **Step 1: Focused regression run** (isolated DB): `docker compose exec -T -e DB_DATABASE=ogami_test_rules api php artisan test --filter='Punch|Attendance|ShiftAssignment|DtrWorkingDays|PayrollAnomaly|PayrollPeriod|OpenAttendance|DTRComputation|PairedCsv|RawPunch|ImportManual|Settings|SelfService|WidgetSeedIntegrity|ApprovalChainRolePermissionDrift'` → PASS (report the skip count: tests needing `working_days` skip only if the column is absent).
- [ ] **Step 2: SPA**: `cd spa && npx vitest run && npx tsc --noEmit && npm run audit:tokens && npm run lint` → PASS except any failures that were already present before this work (list them with the failing assertion).
- [ ] **Step 3: Committed-state proof**: from a `git archive HEAD` scratch copy run `tsc --noEmit` and the new SPA tests (Global Constraints), and from a scratch DB that does NOT have the user's `working_days` migration confirm `PunchRulesTest` still passes apart from the two skipped working-days tests. Report how you did it.
- [ ] **Step 4: Report to the controller**: the commit list, the skipped tests and why, every legacy test whose timings you changed, the `dedupe_key` finding from Task 5 and any additional `is_resolved` sites from Task 6.

---

## Self-review

**Spec coverage:** decisions 1 (early out) → Task 4 `earliestOut`; 2 (min work, latest IN) → Task 4; 3 (late-in first IN, exemptions) → Task 4 `assertNotTooLateIn`; 4 (non-working-day authorization via Approved OT, holidays, start-date) → Task 4; 5 (working days, null default) → Tasks 2–3; 6 (department heads, payroll warning) → Tasks 5–6; 7 (no gap tracking) → Task 4 test + modified lunch test; settings → Task 1; status `earliest_out`/SPA hints → Tasks 4, 7; dependency note on uncommitted `working_days` → Global Constraints, `assignShift` skip, `isWorkingDay` fallback. Out-of-scope items are not built.
**Placeholder scan:** none; two "read file X first and mirror it" steps (Task 1 settings URL, Task 6 anomaly test setup) name the file and what to copy because the exact route/fixture shape was not read during planning.
**Type consistency:** `assignmentFor`/`isWorkingDay`, `earliestOut(): array{at, reason}`, `earliest_out`, rejection codes and setting keys are identical across Tasks 1–7.
