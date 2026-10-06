# Time In / Time Out (geofenced self-service punch) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any employee punch time in/out from their phone inside the plant geofence, feeding the existing `attendances` row so DTR, OT and payroll are unchanged.

**Architecture:** New `attendance_punches` log + `PunchService` in the Attendance module. The service validates GPS against `company.latitude/longitude` and a radius setting, decides direction from the last punch, and writes/updates the day's `attendances` row through the existing `DTRComputationService`. `DTRImportService` merges logged app punches (earliest in, latest out) instead of overwriting them. The SPA gets a Time In/Out card on the self-service home.

**Tech Stack:** Laravel 11 / PHP 8.3 / PostgreSQL 16 / PHPUnit; React 18 + Vite + TypeScript + TanStack Query + Vitest; Playwright (Chromium); nginx.

**Spec:** `docs/superpowers/specs/2026-10-06-time-in-out-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file; enums for every status/type column; every model uses `HasHashId` and resources return `hash_id`, never raw `id`.
- Controllers thin; business logic in the Service inside `DB::transaction()`; authorization in `FormRequest::authorize()` or route middleware.
- Migrations: confirm the real max with `ls api/database/migrations | grep -E '^05' | sort | tail -3` and `ls api/database/migrations | grep -E '^04' | sort | tail -3`; the plan assumes `0500_`/`0501_` are free (highest seen was 0499). Both migrations only touch `employees`, `attendances`, `settings`, `permissions`, `roles`, `role_permissions` — before naming, run `grep -rln "attendances\|'settings'\|'permissions'" api/database/migrations | sort | tail -3`; if any `2026_*` file is the latest creator of a table touched here, name these `2026_10_07_*` instead (the migrator sorts `0…` before `2026_…`).
- Tests: use `'XX-T-'.substr(uniqid(), -5)` for varchar-limited seeds; users via `User::factory()->create(['employee_id' => …, 'role_id' => Role::query()->where('slug', X)->value('id')])`, never `assignRole()`; time-box with `Carbon::setTestNow()`.
- Run PHP tests on an isolated DB, never the shared `ogami_test`: `docker compose exec -T db psql -U ogami -d postgres -c "CREATE DATABASE ogami_test_punch OWNER ogami;"` then `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter=…`. PHP memory: `docker compose exec -T -u root api bash -c "echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/zz-mem.ini"`.
- Decimals reach the SPA as strings; ids are HashID strings; use `font-mono tabular-nums` for times; statuses via `<Chip>`; every mutation has `toast.success`/`toast.error` + `invalidateQueries`; no hardcoded colours (`npm run audit:tokens`).
- API paths are `/api/v1/attendance/...` (module prefix `attendance`). Literal route segments go before `{model}` bindings.
- Commit after each task: `feat: time in/out — <what>` ending with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Spec amendments discovered while planning (already applied to the spec)

1. Endpoints live in `Attendance/routes.php` (`POST /attendance/punch`, `GET /attendance/punch/status`), not under `hr/self-service`, so the `feature:attendance` module toggle gates them.
2. A day whose row is an HR manual entry (`is_manual_entry`) or `on_leave` rejects the punch (`manual_entry` / `on_leave` codes), mirroring `DTRImportService::manualCorrectionGuard` (AT-02).
3. Direction is `out` only when the last punch was an `in` within the last 16 hours; a stale `in` older than that starts a new day with `in`.
4. The late warning reads `attendances.tardiness_minutes` already computed by the DTR engine on each punch; nothing new is stored.
5. The raw-location endpoint is gated by the `attendance.punch.audit` route permission, so its resource always includes coordinates (null after the 90-day purge).
6. There is no attendance detail page, so the HR punch drawer opens from a row action on the list page `/hr/attendance`.
7. The Time In/Out card renders on the existing self-service home (`pages/self-service/index.tsx`); no new route.

## Review Focus

- Night shift 22:00–06:00: `in` at 22:05, `out` at 06:02 next day attaches to the original row and computes hours (Task 2 test).
- Employee with an `in` older than 16 h (forgot to punch out yesterday) punches again today: must be `in`, not `out` (Task 2 test).
- Two taps within 60 s: second is rejected, not a phantom out (Task 2 test).
- CSV import after an app punch for the same day must not lose the app punch, and a replay of the same CSV must be idempotent (Task 4 tests).
- Admin sets `company.latitude/longitude` to a decimal through the settings API: stored-type guard must accept it (Task 1 test).

---

### Task 1: Foundations — enum, model, migrations, settings, distance helper

**Files:**
- Create: `api/app/Modules/Attendance/Enums/PunchDirection.php`
- Create: `api/app/Modules/Attendance/Models/AttendancePunch.php`
- Create: `api/app/Modules/Attendance/Support/GeoDistance.php`
- Create: `api/app/Modules/Attendance/Exceptions/PunchRejectedException.php`
- Create: `api/database/migrations/0500_create_attendance_punches_table.php`
- Create: `api/database/migrations/0501_seed_punch_settings_and_audit_permission.php`
- Modify: `api/database/seeders/RolePermissionSeeder.php` (attendance catalog, after `attendance.ot.approve`, ~line 102)
- Modify: `api/database/seeders/SettingsSeeder.php` (after `company.longitude`, ~line 72)
- Modify: `api/app/Modules/Admin/Requests/UpdateSettingRequest.php` (`$policyRules`, near line 364)
- Test: `api/tests/Unit/Attendance/GeoDistanceTest.php`, `api/tests/Feature/Attendance/PunchSettingsTest.php`

**Interfaces:**
- Produces: `PunchDirection::In|Out` (`'in'|'out'`); `AttendancePunch` model (fillable `employee_id, attendance_id, direction, punched_at, latitude, longitude, accuracy_m, distance_m, ip, user_agent`; relations `employee()`, `attendance()`); `GeoDistance::meters(float $lat1, float $lng1, float $lat2, float $lng2): float`; `PunchRejectedException(string $code, string $message)` with `errorCode(): string`; settings `company.punch_radius_m`=200, `company.punch_max_accuracy_m`=100, `attendance.punch_early_minutes`=120, `attendance.punch_late_minutes`=240; permission `attendance.punch.audit` (hr_officer, system_admin).

- [ ] **Step 1: Write the failing tests**

`api/tests/Unit/Attendance/GeoDistanceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Attendance;

use App\Modules\Attendance\Support\GeoDistance;
use PHPUnit\Framework\TestCase;

class GeoDistanceTest extends TestCase
{
    public function test_same_point_is_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, GeoDistance::meters(14.2860, 120.9345, 14.2860, 120.9345), 0.001);
    }

    public function test_one_millidegree_of_latitude_is_about_111_metres(): void
    {
        $this->assertEqualsWithDelta(111.2, GeoDistance::meters(14.2860, 120.9345, 14.2870, 120.9345), 0.5);
    }

    public function test_is_symmetric(): void
    {
        $a = GeoDistance::meters(14.2860, 120.9345, 14.30, 120.95);
        $b = GeoDistance::meters(14.30, 120.95, 14.2860, 120.9345);
        $this->assertEqualsWithDelta($a, $b, 0.001);
    }
}
```

`api/tests/Feature/Attendance/PunchSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PunchSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_punch_settings_and_permission_exist_after_migrate(): void
    {
        $s = app(SettingsService::class);
        $this->assertSame(200.0, $s->requiredFloat('company.punch_radius_m'));
        $this->assertSame(100.0, $s->requiredFloat('company.punch_max_accuracy_m'));
        $this->assertSame(120, $s->requiredInt('attendance.punch_early_minutes'));
        $this->assertSame(240, $s->requiredInt('attendance.punch_late_minutes'));
        $this->assertDatabaseHas('permissions', ['slug' => 'attendance.punch.audit']);
    }

    public function test_admin_can_set_plant_centre_as_decimals(): void
    {
        $admin = User::factory()->create(['role_id' => Role::query()->where('slug', 'system_admin')->value('id')]);

        $this->actingAs($admin)->putJson('/api/v1/admin/settings/company.latitude', ['value' => 14.2861])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/company.longitude', ['value' => 120.9346])->assertOk();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/company.latitude', ['value' => 95])->assertStatus(422);
        $this->actingAs($admin)->putJson('/api/v1/admin/settings/company.punch_radius_m', ['value' => 0])->assertStatus(422);
    }
}
```

Before running, confirm the settings update route: `grep -n "settings" api/app/Modules/Admin/routes.php` and use the exact URL/verb it declares (adjust the two `putJson` URLs if it is `patch` or a different prefix).

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter='GeoDistanceTest|PunchSettingsTest'`
Expected: FAIL (class `GeoDistance` not found; settings missing).

- [ ] **Step 3: Implement**

`PunchDirection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum PunchDirection: string
{
    case In = 'in';
    case Out = 'out';
}
```

`GeoDistance.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

final class GeoDistance
{
    private const EARTH_RADIUS_M = 6371008.8;

    /** Great-circle (haversine) distance in metres. */
    public static function meters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
```

`PunchRejectedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A punch the rules refuse. Rendered as 422 with `code` by bootstrap/app.php. */
class PunchRejectedException extends BusinessRuleException
{
    public function __construct(private readonly string $code_, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->code_;
    }
}
```

`AttendancePunch.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePunch extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = [
        'employee_id', 'attendance_id', 'direction', 'punched_at',
        'latitude', 'longitude', 'accuracy_m', 'distance_m', 'ip', 'user_agent',
    ];

    protected $casts = [
        'direction' => PunchDirection::class,
        'punched_at' => 'datetime',
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
        'accuracy_m' => 'decimal:2',
        'distance_m' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
```

`0500_create_attendance_punches_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('attendance_id')->nullable()->constrained('attendances');
            $table->string('direction', 3);
            $table->timestamp('punched_at');
            $table->decimal('latitude', 9, 6)->nullable();   // nulled after 90 days
            $table->decimal('longitude', 9, 6)->nullable();  // nulled after 90 days
            $table->decimal('accuracy_m', 7, 2);
            $table->decimal('distance_m', 9, 2);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'punched_at']);
            $table->index('attendance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
```

`0501_seed_punch_settings_and_audit_permission.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTINGS = [
        ['company.punch_radius_m', 200, 'company', 'Time in/out radius (m)', 'Distance from the plant centre within which an employee may time in/out.'],
        ['company.punch_max_accuracy_m', 100, 'company', 'Time in/out max GPS error (m)', 'Worst GPS accuracy accepted for a time in/out.'],
        ['attendance.punch_early_minutes', 120, 'attendance', 'Punch window: minutes before shift', 'How early before shift start an employee may time in.'],
        ['attendance.punch_late_minutes', 240, 'attendance', 'Punch window: minutes after shift', 'How late after shift end an employee may still time out.'],
    ];

    private const SLUG = 'attendance.punch.audit';

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('permissions')->updateOrInsert(
            ['slug' => self::SLUG],
            ['name' => 'View Time In/Out Location Audit', 'module' => 'attendance', 'updated_at' => now(), 'created_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');
        foreach (DB::table('roles')->whereIn('slug', ['hr_officer', 'system_admin'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::SETTINGS, 0))->delete();
        DB::table('permissions')->where('slug', self::SLUG)->delete();
    }
};
```

If `roles` rows do not exist at migrate time in the test DB (seeded later), the grant loop is a no-op there; `RolePermissionSeeder` (next edit) grants it on seed. That is intended.

`RolePermissionSeeder.php` — add inside the `'attendance'` array after the `attendance.ot.approve` line:

```php
                ['slug' => 'attendance.punch.audit',   'name' => 'View Time In/Out Location Audit'],
```
`hr_officer` uses `$this->module('attendance')` (grants it automatically); `system_admin` is `'*'`. Check no other role calls `module('attendance')`: `grep -n "module('attendance'" api/database/seeders/RolePermissionSeeder.php` must show only `hr_officer` (line ~522). If another role appears, pass `except: ['attendance.punch.audit']` for it.

`SettingsSeeder.php` — after the `company.longitude` row add:

```php
            ['key' => 'company.punch_radius_m', 'value' => 200, 'group' => 'company', 'label' => 'Time in/out radius (m)', 'description' => 'Distance from the plant centre within which an employee may time in/out.'],
            ['key' => 'company.punch_max_accuracy_m', 'value' => 100, 'group' => 'company', 'label' => 'Time in/out max GPS error (m)', 'description' => 'Worst GPS accuracy accepted for a time in/out.'],
            ['key' => 'attendance.punch_early_minutes', 'value' => 120, 'group' => 'attendance', 'label' => 'Punch window: minutes before shift', 'description' => 'How early before shift start an employee may time in.'],
            ['key' => 'attendance.punch_late_minutes', 'value' => 240, 'group' => 'attendance', 'label' => 'Punch window: minutes after shift', 'description' => 'How late after shift end an employee may still time out.'],
```

`UpdateSettingRequest.php` — add to `$policyRules` (before the closing `];` at ~line 417):

```php
            'company.latitude' => ['value' => ['required', 'numeric', 'between:-90,90']],
            'company.longitude' => ['value' => ['required', 'numeric', 'between:-180,180']],
            'company.punch_radius_m' => ['value' => ['required', 'numeric', 'between:10,5000']],
            'company.punch_max_accuracy_m' => ['value' => ['required', 'numeric', 'between:5,1000']],
            'attendance.punch_early_minutes' => ['value' => ['required', 'integer', 'between:0,720']],
            'attendance.punch_late_minutes' => ['value' => ['required', 'integer', 'between:0,720']],
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter='GeoDistanceTest|PunchSettingsTest'`
Expected: PASS. If `test_admin_can_set_plant_centre_as_decimals` fails with "must keep the setting's existing JSON type", the stored `company.latitude` is a JSON string: write a one-statement fix migration `0502_normalise_company_coordinates_to_numbers.php` that rewrites both rows with `json_encode((float) json_decode($value))`, then re-run. (Seeder uses `$company['latitude']`; confirm its type with `grep -n "latitude" api/database/seeders/SettingsSeeder.php | head`.)

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Attendance api/database api/tests api/app/Modules/Admin/Requests/UpdateSettingRequest.php
git commit -m "feat: time in/out — punch table, settings, audit permission, distance helper

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: PunchService (rules + write path)

**Files:**
- Create: `api/app/Modules/Attendance/Services/PunchService.php`
- Create: `api/tests/Feature/Attendance/Concerns/PunchFixtures.php`
- Test: `api/tests/Feature/Attendance/PunchServiceTest.php`

**Interfaces:**
- Consumes: Task 1 (`PunchDirection`, `AttendancePunch`, `GeoDistance`, `PunchRejectedException`, settings); existing `SettingsService::requiredFloat/requiredInt`, `ShiftAssignmentService::current(Employee, CarbonInterface): ?Shift`, `DTRComputationService::computeForRecord(Attendance): Attendance`, `OvertimeService::autoDetectFromAttendance(Attendance)`, `AttendanceDateMutabilityGuard::assertMutable(int, string)`.
- Produces: `PunchService::punch(Employee $employee, float $lat, float $lng, float $accuracyM, ?string $ip, ?string $userAgent, ?CarbonImmutable $now = null): AttendancePunch`; `PunchService::status(Employee $employee, ?CarbonImmutable $now = null): array{next_action:string, shift:?array{name:string,start:string,end:string}, punches:list<array{direction:string,punched_at:string}>, tardiness_minutes:?int, radius_m:float}`; constants `COOLDOWN_SECONDS=60`, `OPEN_LOOKBACK_HOURS=16`. Rejection codes: `low_accuracy`, `out_of_range`, `too_soon`, `no_shift`, `outside_window`, `row_removed`, `on_leave`, `manual_entry`, `out_before_in`.

- [ ] **Step 1: Write the fixtures trait and failing tests**

`api/tests/Feature/Attendance/Concerns/PunchFixtures.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance\Concerns;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

trait PunchFixtures
{
    protected function plant(): void
    {
        $s = app(SettingsService::class);
        $s->set('company.latitude', 14.2860);
        $s->set('company.longitude', 120.9345);
        $s->set('company.punch_radius_m', 200);
        $s->set('company.punch_max_accuracy_m', 100);
        $s->set('attendance.punch_early_minutes', 120);
        $s->set('attendance.punch_late_minutes', 240);
    }

    protected function shift(string $start = '06:00:00', string $end = '14:00:00', bool $night = false): Shift
    {
        Shift::query()->update(['is_default' => false]);

        return Shift::create([
            'name' => 'XX-T-'.substr(uniqid(), -5),
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => 60,
            'grace_minutes' => 0,
            'is_night_shift' => $night,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    protected function worker(): array
    {
        $employee = Employee::factory()->create();

        return [$employee, User::factory()->create(['employee_id' => $employee->id])];
    }

    protected function at(string $datetime): CarbonImmutable
    {
        $now = CarbonImmutable::parse($datetime);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        return $now;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
```

`api/tests/Feature/Attendance/PunchServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Exceptions\PunchRejectedException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\PunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchServiceTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private const LAT = 14.2860;
    private const LNG = 120.9345;

    private function punch($employee, float $lat = self::LAT, float $lng = self::LNG, float $acc = 20.0)
    {
        return app(PunchService::class)->punch($employee, $lat, $lng, $acc, '127.0.0.1', 'phpunit', null);
    }

    private function assertRejected(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected PunchRejectedException [{$code}]");
        } catch (PunchRejectedException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift();
    }

    public function test_in_then_out_writes_one_attendance_row(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $in = $this->punch($emp);
        $this->assertSame(PunchDirection::In, $in->direction);

        $this->at('2026-10-07 14:05:00');
        $out = $this->punch($emp);
        $this->assertSame(PunchDirection::Out, $out->direction);

        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('2026-10-07', $row->date->toDateString());
        $this->assertSame('06:02', $row->time_in->format('H:i'));
        $this->assertSame('14:05', $row->time_out->format('H:i'));
        $this->assertSame($row->id, $out->attendance_id);
        $this->assertNotNull($row->status);
    }

    public function test_out_of_range_is_rejected_and_nothing_is_written(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->assertRejected('out_of_range', fn () => $this->punch($emp, 14.30, 120.95));
        $this->assertSame(0, AttendancePunch::query()->count());
        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_poor_accuracy_is_rejected(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->assertRejected('low_accuracy', fn () => $this->punch($emp, self::LAT, self::LNG, 250.0));
    }

    public function test_second_tap_within_cooldown_is_rejected(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->punch($emp);
        $this->at('2026-10-07 06:02:30');
        $this->assertRejected('too_soon', fn () => $this->punch($emp));
        $this->assertSame(1, AttendancePunch::query()->count());
    }

    public function test_outside_window_is_rejected(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 03:00:00'); // 06:00 shift, window opens 04:00
        $this->assertRejected('outside_window', fn () => $this->punch($emp));
    }

    public function test_extra_in_after_out_keeps_first_in_and_last_out(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 12:00:00');
        $this->punch($emp);               // out (lunch)
        $this->at('2026-10-07 13:00:00');
        $in2 = $this->punch($emp);        // back in
        $this->assertSame(PunchDirection::In, $in2->direction);
        $this->at('2026-10-07 14:10:00');
        $this->punch($emp);               // final out

        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:00', $row->time_in->format('H:i'));
        $this->assertSame('14:10', $row->time_out->format('H:i'));
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(4, AttendancePunch::query()->count());
    }

    public function test_stale_in_older_than_16_hours_starts_a_new_day_with_in(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-06 06:00:00');
        $this->punch($emp);               // forgot to punch out
        $this->at('2026-10-07 06:00:00');
        $next = $this->punch($emp);
        $this->assertSame(PunchDirection::In, $next->direction);
        $this->assertSame(2, Attendance::query()->where('employee_id', $emp->id)->count());
    }

    public function test_night_shift_out_after_midnight_attaches_to_start_day(): void
    {
        $this->shift('22:00:00', '06:00:00', true);
        [$emp] = $this->worker();
        $this->at('2026-10-07 22:05:00');
        $this->punch($emp);
        $this->at('2026-10-08 06:02:00');
        $out = $this->punch($emp);

        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('2026-10-07', $row->date->toDateString());
        $this->assertSame($row->id, $out->attendance_id);
        $this->assertSame('06:02', $row->time_out->format('H:i'));
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_hr_manual_entry_and_leave_days_are_not_overwritten(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-10-07',
            'time_in' => '2026-10-07 06:30:00', 'time_out' => '2026-10-07 14:00:00',
            'status' => AttendanceStatus::Present->value, 'is_manual_entry' => true,
        ]);
        $this->assertRejected('manual_entry', fn () => $this->punch($emp));
    }

    public function test_archived_row_rejects_the_punch(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $row = Attendance::create([
            'employee_id' => $emp->id, 'date' => '2026-10-07', 'status' => AttendanceStatus::Absent->value,
        ]);
        $row->delete();
        $this->assertRejected('row_removed', fn () => $this->punch($emp));
    }

    public function test_no_shift_and_no_default_is_rejected(): void
    {
        \App\Modules\Attendance\Models\Shift::query()->delete();
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        $this->assertRejected('no_shift', fn () => $this->punch($emp));
    }

    public function test_status_reports_next_action_and_tardiness(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:15:00');
        $before = app(PunchService::class)->status($emp);
        $this->assertSame('in', $before['next_action']);

        $this->punch($emp);
        $after = app(PunchService::class)->status($emp);
        $this->assertSame('out', $after['next_action']);
        $this->assertGreaterThanOrEqual(15, $after['tardiness_minutes']);
        $this->assertCount(1, $after['punches']);
    }
}
```

Note on `test_hr_manual_entry…`: the on-leave branch shares the same guard; add a second assertion with `'status' => AttendanceStatus::OnLeave->value, 'is_manual_entry' => false` and code `on_leave` in a separate test method if the first passes.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter=PunchServiceTest`
Expected: FAIL (class `PunchService` not found).

- [ ] **Step 3: Implement `PunchService`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Exceptions\PunchRejectedException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\GeoDistance;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Geofenced self-service time in/out.
 *
 * Every accepted punch is logged in attendance_punches and folded into the
 * employee-day's attendances row (first in wins, last out wins) through the
 * same DTR engine the biometric import uses, so payroll is untouched.
 */
class PunchService
{
    public const COOLDOWN_SECONDS = 60;
    public const OPEN_LOOKBACK_HOURS = 16;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ShiftAssignmentService $shifts,
        private readonly DTRComputationService $dtr,
        private readonly OvertimeService $overtime,
        private readonly AttendanceDateMutabilityGuard $mutability,
    ) {}

    public function punch(
        Employee $employee,
        float $lat,
        float $lng,
        float $accuracyM,
        ?string $ip,
        ?string $userAgent,
        ?CarbonImmutable $now = null,
    ): AttendancePunch {
        $now ??= CarbonImmutable::now();
        $distance = $this->assertInRange($lat, $lng, $accuracyM);

        return DB::transaction(function () use ($employee, $lat, $lng, $accuracyM, $ip, $userAgent, $now, $distance): AttendancePunch {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $last = $this->lastPunch($employee->id);
            if ($last !== null && $last->punched_at->greaterThan($now->subSeconds(self::COOLDOWN_SECONDS))) {
                throw new PunchRejectedException('too_soon', 'You just punched. Wait a minute before punching again.');
            }

            $open = $this->isOpen($last, $now);
            $direction = $open ? PunchDirection::Out : PunchDirection::In;

            $shift = $this->resolveShift($employee, $now)
                ?? throw new PunchRejectedException('no_shift', 'No shift is assigned to you. Ask HR to configure a shift.');
            $anchor = $this->windowDate($shift, $now)
                ?? throw new PunchRejectedException('outside_window', 'You can only time in/out close to your shift hours. See HR if you worked outside them.');

            $row = $open
                ? Attendance::withTrashed()->lockForUpdate()->find($last->attendance_id)
                : Attendance::withTrashed()->lockForUpdate()
                    ->where('employee_id', $employee->id)->where('date', $anchor->toDateString())->first();

            if ($row !== null) {
                $this->guardRow($row);
            } elseif ($open) {
                throw new PunchRejectedException('row_removed', 'Your attendance record for this shift was removed. Contact HR.');
            }

            $date = $row?->date->toDateString() ?? $anchor->toDateString();
            $this->mutability->assertMutable($employee->id, $date);

            $a = $row ?? new Attendance(['employee_id' => $employee->id, 'date' => $date]);
            if ($direction === PunchDirection::In) {
                $a->time_in = $a->time_in !== null && $a->time_in->lessThan($now) ? $a->time_in : $now;
            } else {
                if ($a->time_in !== null && $now->lessThanOrEqualTo($a->time_in)) {
                    throw new PunchRejectedException('out_before_in', 'Time out must be after your time in.');
                }
                $a->time_out = $a->time_out !== null && $a->time_out->greaterThan($now) ? $a->time_out : $now;
            }

            $a = $this->dtr->computeForRecord($a);
            $a->save();
            $this->overtime->autoDetectFromAttendance($a);

            return AttendancePunch::create([
                'employee_id' => $employee->id,
                'attendance_id' => $a->id,
                'direction' => $direction,
                'punched_at' => $now,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy_m' => $accuracyM,
                'distance_m' => $distance,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 255, '') : null,
            ]);
        });
    }

    /** @return array{next_action:string, shift:?array{name:string,start:string,end:string}, punches:list<array{direction:string,punched_at:string}>, tardiness_minutes:?int, radius_m:float} */
    public function status(Employee $employee, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $last = $this->lastPunch($employee->id);
        $open = $this->isOpen($last, $now);
        $shift = $this->resolveShift($employee, $now);

        $row = $open
            ? Attendance::query()->find($last->attendance_id)
            : Attendance::query()->where('employee_id', $employee->id)->where('date', $now->toDateString())->first();

        $punches = AttendancePunch::query()
            ->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $now->startOfDay())
            ->orderBy('punched_at')->orderBy('id')
            ->get()
            ->map(fn (AttendancePunch $p): array => [
                'direction' => $p->direction->value,
                'punched_at' => $p->punched_at->toIso8601String(),
            ])->all();

        return [
            'next_action' => $open ? PunchDirection::Out->value : PunchDirection::In->value,
            'shift' => $shift ? [
                'name' => $shift->name,
                'start' => substr((string) $shift->start_time, 0, 5),
                'end' => substr((string) $shift->end_time, 0, 5),
            ] : null,
            'punches' => $punches,
            'tardiness_minutes' => $row !== null ? (int) $row->tardiness_minutes : null,
            'radius_m' => $this->settings->requiredFloat('company.punch_radius_m', 1, 5000),
        ];
    }

    private function assertInRange(float $lat, float $lng, float $accuracyM): float
    {
        $cap = $this->settings->requiredFloat('company.punch_max_accuracy_m', 1, 1000);
        $radius = $this->settings->requiredFloat('company.punch_radius_m', 1, 5000);
        $centreLat = $this->settings->requiredFloat('company.latitude', -90, 90);
        $centreLng = $this->settings->requiredFloat('company.longitude', -180, 180);

        if ($accuracyM > $cap) {
            throw new PunchRejectedException('low_accuracy', sprintf('GPS signal is too weak (±%d m). Move to an open area and try again.', (int) round($accuracyM)));
        }
        $distance = GeoDistance::meters($lat, $lng, $centreLat, $centreLng);
        if ($distance > $radius) {
            throw new PunchRejectedException('out_of_range', sprintf('You are %d m from the plant. Time in/out works only within %d m.', (int) round($distance), (int) round($radius)));
        }

        return round($distance, 2);
    }

    private function lastPunch(int $employeeId): ?AttendancePunch
    {
        return AttendancePunch::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('punched_at')->orderByDesc('id')
            ->first();
    }

    /** True when the last punch was an `in` recent enough to still be this shift. */
    private function isOpen(?AttendancePunch $last, CarbonImmutable $now): bool
    {
        return $last !== null
            && $last->direction === PunchDirection::In
            && $last->punched_at->greaterThanOrEqualTo($now->subHours(self::OPEN_LOOKBACK_HOURS));
    }

    private function resolveShift(Employee $employee, CarbonImmutable $now): ?Shift
    {
        return $this->shifts->current($employee, $now)
            ?? Shift::query()->where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * The date of the shift instance whose punch window contains $now, or null.
     * Today's instance is tried before yesterday's (a night shift that started
     * yesterday ends this morning). ponytail: yesterday's instance reuses
     * today's resolved shift; per-day reassignment across midnight is ignored.
     */
    private function windowDate(Shift $shift, CarbonImmutable $now): ?CarbonImmutable
    {
        $early = $this->settings->requiredInt('attendance.punch_early_minutes', 0, 720);
        $late = $this->settings->requiredInt('attendance.punch_late_minutes', 0, 720);

        foreach ([0, -1] as $offset) {
            $day = $now->startOfDay()->addDays($offset);
            $start = $day->setTimeFromTimeString((string) $shift->start_time);
            $end = $day->setTimeFromTimeString((string) $shift->end_time);
            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }
            if ($now->betweenIncluded($start->subMinutes($early), $end->addMinutes($late))) {
                return $day;
            }
        }

        return null;
    }

    private function guardRow(Attendance $row): void
    {
        if ($row->trashed()) {
            throw new PunchRejectedException('row_removed', 'Your attendance record for this day was removed. Contact HR.');
        }
        if ($row->status === AttendanceStatus::OnLeave) {
            throw new PunchRejectedException('on_leave', 'You have approved leave on this day, so you cannot time in/out.');
        }
        if ($row->is_manual_entry) {
            throw new PunchRejectedException('manual_entry', 'HR corrected this day\'s attendance. Contact HR to change it.');
        }
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter=PunchServiceTest`
Expected: PASS (11 tests). If `test_night_shift…` fails on `InvalidArgumentException` from the engine, re-read `DTRComputationService::compute()` lines 131–190 and confirm the shift row has `is_night_shift = true`; do not loosen the service. If `betweenIncluded` is unavailable on the installed Carbon, replace with `$now->gte($lo) && $now->lte($hi)`.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Attendance/Services/PunchService.php api/tests/Feature/Attendance
git commit -m "feat: time in/out — PunchService with geofence, window and day anchoring

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Employee endpoints (status + punch)

**Files:**
- Create: `api/app/Modules/Attendance/Requests/PunchRequest.php`
- Create: `api/app/Modules/Attendance/Controllers/PunchController.php`
- Modify: `api/app/Modules/Attendance/routes.php` (inside the `attendance` group, before `// Overtime requests`)
- Test: `api/tests/Feature/Attendance/PunchEndpointTest.php`

**Interfaces:**
- Consumes: `PunchService::punch/status` (Task 2); `PunchRejectedException` → 422 `{message, errors, code}` via `bootstrap/app.php`.
- Produces: `GET /api/v1/attendance/punch/status` → `{data: <status array>}`; `POST /api/v1/attendance/punch` body `{lat, lng, accuracy}` → 201 `{data: {direction, punched_at, distance_m, status: <status array>}}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchEndpointTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift();
    }

    private function body(array $over = []): array
    {
        return $over + ['lat' => 14.2860, 'lng' => 120.9345, 'accuracy' => 20];
    }

    public function test_employee_punches_in_then_sees_next_action_out(): void
    {
        [, $user] = $this->worker();
        $this->at('2026-10-07 06:02:00');

        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body())
            ->assertCreated()
            ->assertJsonPath('data.direction', 'in')
            ->assertJsonPath('data.status.next_action', 'out')
            ->assertJsonMissingPath('data.id');

        $this->actingAs($user)->getJson('/api/v1/attendance/punch/status')
            ->assertOk()
            ->assertJsonPath('data.next_action', 'out')
            ->assertJsonCount(1, 'data.punches');
    }

    public function test_out_of_range_returns_422_with_code(): void
    {
        [, $user] = $this->worker();
        $this->at('2026-10-07 06:02:00');

        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body(['lat' => 14.30, 'lng' => 120.95]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'out_of_range');
    }

    public function test_validation_rejects_missing_or_absurd_coordinates(): void
    {
        [, $user] = $this->worker();
        $this->actingAs($user)->postJson('/api/v1/attendance/punch', [])->assertStatus(422)->assertJsonValidationErrors(['lat', 'lng', 'accuracy']);
        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body(['lat' => 123]))->assertStatus(422)->assertJsonValidationErrors(['lat']);
    }

    public function test_user_without_employee_is_forbidden(): void
    {
        $user = User::factory()->create(['employee_id' => null]);
        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body())->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/attendance/punch/status')->assertForbidden();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson('/api/v1/attendance/punch', $this->body())->assertUnauthorized();
    }

    public function test_employee_is_taken_from_session_never_from_body(): void
    {
        [, $user] = $this->worker();
        [$other] = $this->worker();
        $this->at('2026-10-07 06:02:00');

        $this->actingAs($user)->postJson('/api/v1/attendance/punch', $this->body(['employee_id' => $other->id]))->assertCreated();
        $this->assertDatabaseMissing('attendance_punches', ['employee_id' => $other->id]);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter=PunchEndpointTest`
Expected: FAIL (404 — routes missing).

- [ ] **Step 3: Implement**

`PunchRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PunchRequest extends FormRequest
{
    /** Self-service only: any authenticated user linked to an employee. */
    public function authorize(): bool
    {
        return $this->user()?->employee_id !== null;
    }

    public function rules(): array
    {
        if (! $this->isMethod('post')) {
            return [];
        }

        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
```

`PunchController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Requests\PunchRequest;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\HR\Models\Employee;
use Illuminate\Http\JsonResponse;

class PunchController
{
    public function __construct(private readonly PunchService $service) {}

    public function status(PunchRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail($request->user()->employee_id);

        return response()->json(['data' => $this->service->status($employee)]);
    }

    public function store(PunchRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail($request->user()->employee_id);

        $punch = $this->service->punch(
            $employee,
            (float) $request->input('lat'),
            (float) $request->input('lng'),
            (float) $request->input('accuracy'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json(['data' => [
            'direction' => $punch->direction->value,
            'punched_at' => $punch->punched_at->toIso8601String(),
            'distance_m' => (string) $punch->distance_m,
            'status' => $this->service->status($employee),
        ]], 201);
    }
}
```

`routes.php` — add the import `use App\Modules\Attendance\Controllers\PunchController;` and inside the group before `// Overtime requests`:

```php
    // Geofenced self-service time in/out. Auth-only: the employee comes from
    // the session (PunchRequest::authorize), never from the request body.
    Route::get('/punch/status', [PunchController::class, 'status']);
    Route::post('/punch', [PunchController::class, 'store'])->middleware('throttle:sensitive');
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter='PunchEndpointTest|PunchServiceTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Attendance api/tests/Feature/Attendance/PunchEndpointTest.php
git commit -m "feat: time in/out — employee punch and status endpoints

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Biometric import merges app punches

**Files:**
- Modify: `api/app/Modules/Attendance/Services/DTRImportService.php` (both write blocks, ~lines 114–126 and ~lines 277–285; add one private method)
- Test: `api/tests/Feature/Attendance/PunchImportMergeTest.php`

**Interfaces:**
- Consumes: `AttendancePunch` (Task 1), `PunchService::punch` (Task 2) for fixtures.
- Produces: private `DTRImportService::mergeAppPunches(Attendance $a, ?string $tIn, ?string $tOut): array{0:?string,1:?string}` — earliest in / latest out across the file and the day's logged app punches.

- [ ] **Step 1: Write the failing tests**

Model the fixtures on `PairedCsvImportTest` (employee_no CSV header `employee_no,date,time_in,time_out`) and `RawPunchImportTest` (raw punches path: read how it builds its `UploadedFile` and column header, and reuse the same helper style).

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\DTRImportService;
use App\Modules\Attendance\Services\PunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchImportMergeTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift();
    }

    private function csv(string $employeeNo, string $in, string $out): UploadedFile
    {
        $body = "employee_no,date,time_in,time_out\n{$employeeNo},2026-10-07,{$in},{$out}\n";

        return UploadedFile::fake()->createWithContent('dtr.csv', $body);
    }

    private function appPunchDay($emp): void
    {
        $svc = app(PunchService::class);
        $this->at('2026-10-07 06:10:00');
        $svc->punch($emp, 14.2860, 120.9345, 20.0, null, null);
        $this->at('2026-10-07 14:20:00');
        $svc->punch($emp, 14.2860, 120.9345, 20.0, null, null);
    }

    public function test_paired_import_keeps_earliest_in_and_latest_out_across_sources(): void
    {
        [$emp] = $this->worker();
        $this->appPunchDay($emp);

        $result = app(DTRImportService::class)->import($this->csv($emp->employee_no, '06:00', '14:00'));

        $this->assertSame(1, $result['imported']);
        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:00', $row->time_in->format('H:i'));  // CSV earlier
        $this->assertSame('14:20', $row->time_out->format('H:i')); // app later
    }

    public function test_replaying_the_same_file_is_idempotent(): void
    {
        [$emp] = $this->worker();
        $this->appPunchDay($emp);
        $svc = app(DTRImportService::class);

        $svc->import($this->csv($emp->employee_no, '06:00', '14:00'));
        $again = $svc->import($this->csv($emp->employee_no, '06:00', '14:00'));

        $this->assertSame(0, $again['skipped_manual']);
        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:00', $row->time_in->format('H:i'));
        $this->assertSame('14:20', $row->time_out->format('H:i'));
    }

    public function test_import_without_app_punches_behaves_as_before(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 15:00:00');

        app(DTRImportService::class)->import($this->csv($emp->employee_no, '06:03', '14:01'));

        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('06:03', $row->time_in->format('H:i'));
        $this->assertSame('14:01', $row->time_out->format('H:i'));
    }
}
```

Add a fourth test for the raw-punch path (`importRawPunches`): mirror the file shape used in `RawPunchImportTest`, with one raw punch at 06:30 for the day after `appPunchDay()` — assert `time_in` stays 06:10 and `time_out` stays 14:20.

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter=PunchImportMergeTest`
Expected: FAIL (import overwrites with CSV values: `time_out` is 14:00, not 14:20).

- [ ] **Step 3: Implement**

In `DTRImportService.php`, add imports `use App\Modules\Attendance\Enums\PunchDirection; use App\Modules\Attendance\Models\AttendancePunch;`.

Paired path — replace the transaction body lines from `$a = $this->openDayRecord(...)` through `$a->time_out = $tOut;` with:

```php
                    $a = $this->openDayRecord($employeeId, $date);
                    [$tIn, $tOut] = $this->mergeAppPunches($a, $tIn, $tOut);
                    $guarded = $this->manualCorrectionGuard($a, $tIn, $tOut);
                    if ($guarded !== null) {
                        return $guarded;
                    }
                    $a->time_in = $tIn;
                    $a->time_out = $tOut;
```
and change that closure's `use (...)` list to `use ($employeeId, $date, $tIn, $tOut)` by-value as before (the reassignment is local to the closure; if PHP reports the by-value reassignment as unused outside, that is fine).

Raw-punch path — same change inside its transaction using `$day['time_in']` / `$day['time_out']`:

```php
                    $a = $this->openDayRecord($employeeId, $date);
                    [$mIn, $mOut] = $this->mergeAppPunches($a, $day['time_in'], $day['time_out']);
                    $guarded = $this->manualCorrectionGuard($a, $mIn, $mOut);
                    if ($guarded !== null) {
                        return $guarded;
                    }
                    $a->time_in = $mIn;
                    $a->time_out = $mOut;
```

New private method (place after `openDayRecord`):

```php
    /**
     * Fold the day's logged app punches (attendance_punches) into the file's
     * punches: earliest in, latest out. Without this a later biometric import
     * overwrote an employee's own punch. A day with no app punches returns the
     * file's values untouched, so existing imports behave exactly as before.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function mergeAppPunches(Attendance $a, ?string $tIn, ?string $tOut): array
    {
        if (! $a->exists) {
            return [$tIn, $tOut];
        }

        $punches = AttendancePunch::query()->where('attendance_id', $a->id);
        $appIn = (clone $punches)->where('direction', PunchDirection::In->value)->min('punched_at');
        $appOut = (clone $punches)->where('direction', PunchDirection::Out->value)->max('punched_at');

        $in = collect([$tIn, $appIn])->filter()->map(fn ($v) => Carbon::parse($v)->toDateTimeString())->min();
        $out = collect([$tOut, $appOut])->filter()->map(fn ($v) => Carbon::parse($v)->toDateTimeString())->max();

        return [$in, $out];
    }
```
`Carbon` is already imported in this file (used in `import()`); confirm with `grep -n "^use Illuminate\\\\Support\\\\Carbon\|^use Carbon" api/app/Modules/Attendance/Services/DTRImportService.php` and add the import if missing. ISO-8601 strings sort lexicographically after `toDateTimeString()`, so `min`/`max` on strings is correct.

- [ ] **Step 4: Run to verify pass, and the existing import suites**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter='PunchImportMergeTest|PairedCsvImportTest|RawPunchImportTest|ImportManualCorrectionGuardTest|AttendanceArchivedDayAndInputHardeningTest'`
Expected: PASS (existing suites unchanged).

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Attendance/Services/DTRImportService.php api/tests/Feature/Attendance/PunchImportMergeTest.php
git commit -m "feat: time in/out — biometric import merges app punches instead of overwriting

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: HR punch audit endpoint + missing time-out filter

**Files:**
- Create: `api/app/Modules/Attendance/Resources/AttendancePunchResource.php`
- Modify: `api/app/Modules/Attendance/Controllers/AttendanceController.php` (add `punches` action)
- Modify: `api/app/Modules/Attendance/routes.php` (literal route before `{attendance}` group of routes)
- Modify: `api/app/Modules/Attendance/Services/AttendanceService.php` (`list()` filter, after the `status` filter ~line 55)
- Test: `api/tests/Feature/Attendance/PunchAuditTest.php`

**Interfaces:**
- Consumes: `AttendancePunch` (Task 1), permission `attendance.punch.audit` (Task 1).
- Produces: `GET /api/v1/attendance/attendances/{attendance}/punches` → `{data: [{id, direction, punched_at, latitude, longitude, accuracy_m, distance_m, ip}]}`; list filter `?missing_time_out=1` (rows with `time_in` set, `time_out` null, `date` before today).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchAuditTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->plant();
        $this->shift();
    }

    public function test_hr_sees_punch_location_but_a_department_head_does_not(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:02:00');
        app(PunchService::class)->punch($emp, 14.2860, 120.9345, 20.0, '10.0.0.1', 'ua');
        $row = Attendance::query()->where('employee_id', $emp->id)->firstOrFail();

        $this->actingAs($this->userWithRole('hr_officer'))
            ->getJson("/api/v1/attendance/attendances/{$row->hash_id}/punches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.direction', 'in')
            ->assertJsonPath('data.0.distance_m', '0.00');

        $this->actingAs($this->userWithRole('department_head'))
            ->getJson("/api/v1/attendance/attendances/{$row->hash_id}/punches")
            ->assertForbidden();
    }

    public function test_missing_time_out_filter_lists_only_open_past_days(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-08 09:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-06', 'time_in' => '2026-10-06 06:00:00', 'status' => AttendanceStatus::Present->value]);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'time_out' => '2026-10-07 14:00:00', 'status' => AttendanceStatus::Present->value]);
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-08', 'time_in' => '2026-10-08 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->actingAs($this->userWithRole('hr_officer'))
            ->getJson('/api/v1/attendance/attendances?missing_time_out=1&from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '2026-10-06');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter=PunchAuditTest`
Expected: FAIL (404 route; filter ignored so 3 rows returned).

- [ ] **Step 3: Implement**

`AttendancePunchResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Only ever returned by the route gated on `attendance.punch.audit`, so raw
 * coordinates are included. They are null once the 90-day purge has run.
 */
class AttendancePunchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'direction' => $this->direction->value,
            'punched_at' => $this->punched_at->toIso8601String(),
            'latitude' => $this->latitude !== null ? (string) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (string) $this->longitude : null,
            'accuracy_m' => (string) $this->accuracy_m,
            'distance_m' => (string) $this->distance_m,
            'ip' => $this->ip,
        ];
    }
}
```

`AttendanceController.php` — add imports `use App\Modules\Attendance\Models\AttendancePunch; use App\Modules\Attendance\Resources\AttendancePunchResource;` and the action:

```php
    public function punches(Attendance $attendance): AnonymousResourceCollection
    {
        return AttendancePunchResource::collection(
            AttendancePunch::query()->where('attendance_id', $attendance->id)->orderBy('punched_at')->orderBy('id')->get(),
        );
    }
```

`routes.php` — in the `// Attendance` block, directly after the `/attendances/options` line (before `/attendances/{attendance}` GET):

```php
    Route::get('/attendances/{attendance}/punches', [AttendanceController::class, 'punches'])->middleware('permission:attendance.punch.audit');
```

`AttendanceService::list()` — after the `status` filter block:

```php
        if (! empty($filters['missing_time_out'])) {
            $q->whereNotNull('time_in')->whereNull('time_out')->where('date', '<', now()->toDateString());
        }
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter='PunchAuditTest|AttendanceHardeningTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Attendance api/tests/Feature/Attendance/PunchAuditTest.php
git commit -m "feat: time in/out — HR punch audit endpoint and missing time-out filter

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Scheduled commands (missing time-out notice, coordinate purge)

**Files:**
- Create: `api/app/Console/Commands/NotifyMissingTimeOut.php`
- Create: `api/app/Console/Commands/PurgePunchCoordinates.php`
- Modify: `api/routes/console.php` (append two `Schedule::command` entries after `qc:sweep-missing-outgoing`)
- Modify: `api/app/Common/Services/NotificationCatalog.php` (add type in the Chain 3 group, after `attendance.ot_rejected`, ~line 222)
- Test: `api/tests/Feature/Attendance/PunchCommandsTest.php`

**Interfaces:**
- Consumes: `NotificationService::send(User|Collection|array $recipients, string $type, array $data, ?string $dedupeKey = null)`; `AttendancePunch` (Task 1).
- Produces: `attendance:notify-missing-timeout` (exit FAILURE when sends were attempted and all threw); `attendance:purge-punch-coordinates {--days=90}`; notification type `attendance.missing_time_out`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\NotificationService;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchCommandsTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    public function test_purge_nulls_only_coordinates_older_than_the_window(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 12:00:00');
        $old = AttendancePunch::create(['employee_id' => $emp->id, 'direction' => 'in', 'punched_at' => '2026-06-01 06:00:00', 'latitude' => 14.286, 'longitude' => 120.9345, 'accuracy_m' => 20, 'distance_m' => 5]);
        $new = AttendancePunch::create(['employee_id' => $emp->id, 'direction' => 'in', 'punched_at' => '2026-10-01 06:00:00', 'latitude' => 14.286, 'longitude' => 120.9345, 'accuracy_m' => 20, 'distance_m' => 5]);

        $this->artisan('attendance:purge-punch-coordinates')->assertSuccessful();

        $this->assertNull($old->fresh()->latitude);
        $this->assertNull($old->fresh()->longitude);
        $this->assertSame('5.00', (string) $old->fresh()->distance_m);
        $this->assertNotNull($new->fresh()->latitude);
    }

    public function test_notifies_hr_of_yesterdays_open_rows_and_stays_quiet_when_none(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id'), 'is_active' => true]);
        [$emp] = $this->worker();
        $this->at('2026-10-08 06:00:00');

        $this->artisan('attendance:notify-missing-timeout')->assertSuccessful(); // nothing open yet
        $this->assertDatabaseCount('notifications', 0);

        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);
        $this->artisan('attendance:notify-missing-timeout')->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['type' => 'attendance.missing_time_out']);
    }

    public function test_exits_failure_when_every_send_throws(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        User::factory()->create(['role_id' => Role::query()->where('slug', 'hr_officer')->value('id'), 'is_active' => true]);
        [$emp] = $this->worker();
        $this->at('2026-10-08 06:00:00');
        Attendance::create(['employee_id' => $emp->id, 'date' => '2026-10-07', 'time_in' => '2026-10-07 06:00:00', 'status' => AttendanceStatus::Present->value]);

        $this->mock(NotificationService::class, fn ($m) => $m->shouldReceive('send')->andThrow(new \RuntimeException('boom')));

        $this->artisan('attendance:notify-missing-timeout')->assertFailed();
    }
}
```
Before running, confirm the in-app notifications table name and the `type` column: `grep -rn "create('notifications'\|Schema::create('.*notif" api/database/migrations | head -3` and adjust `assertDatabaseHas` / `assertDatabaseCount` to the real table (the `notifications` name above is an assumption).

- [ ] **Step 2: Run to verify failure**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter=PunchCommandsTest`
Expected: FAIL (commands not defined).

- [ ] **Step 3: Implement**

`PurgePunchCoordinates.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Attendance\Models\AttendancePunch;
use Illuminate\Console\Command;

/** RA 10173 — raw GPS is kept 90 days; distance and accuracy stay as the permanent audit trail. */
class PurgePunchCoordinates extends Command
{
    protected $signature = 'attendance:purge-punch-coordinates {--days=90 : Age after which raw coordinates are nulled}';

    protected $description = 'Null raw latitude/longitude on time in/out punches older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $n = AttendancePunch::query()
            ->where('punched_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q->whereNotNull('latitude')->orWhereNotNull('longitude'))
            ->update(['latitude' => null, 'longitude' => null]);

        $this->info("Purged coordinates on {$n} punch(es) older than {$days} days.");

        return self::SUCCESS;
    }
}
```

`NotifyMissingTimeOut.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\NotificationService;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells HR which employees have a time in but no time out for yesterday. An open
 * row computes zero hours, so HR must correct it by hand — never auto-closed,
 * because inventing a time out invents pay data.
 *
 * Distinguishes nothing-to-do (SUCCESS) from everything-failed (FAILURE).
 */
class NotifyMissingTimeOut extends Command
{
    protected $signature = 'attendance:notify-missing-timeout';

    protected $description = 'Notify HR of yesterday\'s attendance rows with a time in but no time out';

    public function handle(NotificationService $notifications): int
    {
        $count = Attendance::query()
            ->whereNotNull('time_in')->whereNull('time_out')
            ->whereDate('date', now()->subDay()->toDateString())
            ->count();

        if ($count === 0) {
            $this->info('No open attendance rows for yesterday.');

            return self::SUCCESS;
        }

        $hr = User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', 'hr_officer'))
            ->where('is_active', true)
            ->get();

        try {
            $notifications->send($hr, 'attendance.missing_time_out', [
                'title' => 'Missing time-out',
                'message' => "{$count} employee(s) have no time out for ".now()->subDay()->format('M j, Y').'.',
                'link_to' => '/hr/attendance?missing_time_out=1',
                'entity_type' => 'attendance',
            ], 'attendance.missing_time_out:'.now()->subDay()->toDateString());
        } catch (Throwable $e) {
            Log::error('attendance:notify-missing-timeout send failed', ['error' => $e->getMessage()]);
            $this->error('Could not send the notification: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Notified HR of {$count} open row(s).");

        return self::SUCCESS;
    }
}
```

`NotificationCatalog.php` — add after the `attendance.ot_rejected` line:

```php
                ['key' => 'attendance.missing_time_out', 'label' => 'Missing time-out', 'description' => 'Employees with a time in but no time out for the previous day need HR correction.'],
```

`routes/console.php` — append after the `qc:sweep-missing-outgoing` entry:

```php
// Time in/out — HR hears about yesterday's open rows at 07:00 (they compute zero
// hours until corrected), and raw GPS is purged after 90 days (RA 10173).
Schedule::command('attendance:notify-missing-timeout')
    ->dailyAt('07:00')
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('attendance:purge-punch-coordinates')
    ->dailyAt('02:30')
    ->withoutOverlapping(10)
    ->onOneServer();
```

If a test asserts the scheduled-command count (CLAUDE.md quotes "43 scheduled commands"), find it with `grep -rn "console.php\|Schedule::" api/tests | head` and update that expectation; also update the stale count in CLAUDE.md's Cron inventory only if the file states it as a hard number the tests pin.

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter='PunchCommandsTest'` then `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan schedule:list | grep attendance`
Expected: PASS; both commands listed.

- [ ] **Step 5: Commit**

```bash
git add api/app/Console api/routes/console.php api/app/Common/Services/NotificationCatalog.php api/tests/Feature/Attendance/PunchCommandsTest.php
git commit -m "feat: time in/out — daily missing time-out notice and 90-day coordinate purge

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: nginx — allow geolocation for the SPA

**Files:**
- Modify: `docker/nginx/security-headers-dev.conf:9`, `docker/nginx/security-headers-prod.conf:9`, `docker/nginx/default.conf:45`, `docker/nginx/prod.conf:100`

- [ ] **Step 1: Edit all four lines**

Replace in each file:

```
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
```
with:

```
add_header Permissions-Policy "camera=(), microphone=(), geolocation=(self)" always;
```
(`default.conf:45` and `prod.conf:100` keep their existing indentation.)

- [ ] **Step 2: Verify**

Run: `grep -rn "Permissions-Policy" docker/nginx` — expected: four lines, all containing `geolocation=(self)`, none containing `geolocation=()`.
Run: `docker compose exec -T nginx nginx -t` (service name per `docker-compose.yml`; use `docker compose ps` to find it). Expected: `syntax is ok`. Then `docker compose exec -T nginx nginx -s reload`.
Run: `curl -sI http://localhost/ | grep -i permissions-policy` — expected `geolocation=(self)`.

- [ ] **Step 3: Commit**

```bash
git add docker/nginx
git commit -m "feat: time in/out — allow same-origin geolocation in Permissions-Policy

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: SPA — Time In/Out card

**Files:**
- Create: `spa/src/api/attendance/punch.ts`
- Create: `spa/src/components/self-service/TimeClockCard.tsx`
- Create: `spa/src/components/self-service/TimeClockCard.test.tsx`
- Modify: `spa/src/pages/self-service/index.tsx` (render the card at the top of the page body)
- Modify: `spa/src/types/attendance.ts` (append the types below)

**Interfaces:**
- Consumes: `GET /attendance/punch/status`, `POST /attendance/punch` (Task 3); `client` from `@/api/client`; `useFeature('attendance')`; `toast` from `react-hot-toast`; `Panel`, `Button`, `Chip`, `SkeletonBlock`, `QueryErrorState` from `@/components/ui`; `formatTime` from `@/lib/formatDate`.
- Produces: `punchApi.status(): Promise<PunchStatus>`, `punchApi.punch(fix: GpsFix): Promise<PunchResult>`; `<TimeClockCard />`.

- [ ] **Step 1: Write the failing test**

`TimeClockCard.test.tsx`:

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { punchApi } from '@/api/attendance/punch';
import { TimeClockCard } from './TimeClockCard';

vi.mock('@/api/attendance/punch', () => ({ punchApi: { status: vi.fn(), punch: vi.fn() } }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const status = {
  next_action: 'in' as const,
  shift: { name: 'Day', start: '06:00', end: '14:00' },
  punches: [],
  tardiness_minutes: null,
  radius_m: 200,
};

function renderCard() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <TimeClockCard />
    </QueryClientProvider>,
  );
}

function mockGeolocation(impl: (ok: PositionCallback, err: PositionErrorCallback) => void) {
  Object.defineProperty(globalThis.navigator, 'geolocation', {
    configurable: true,
    value: { getCurrentPosition: impl },
  });
}

describe('TimeClockCard', () => {
  beforeEach(() => vi.mocked(punchApi.status).mockResolvedValue(status));
  afterEach(() => vi.clearAllMocks());

  it('sends the GPS fix and shows the success state', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.286, longitude: 120.9345, accuracy: 18 } } as GeolocationPosition));
    vi.mocked(punchApi.punch).mockResolvedValue({ direction: 'in', punched_at: '2026-10-07T06:02:00+08:00', distance_m: '4.00', status: { ...status, next_action: 'out' } });
    renderCard();

    fireEvent.click(await screen.findByRole('button', { name: /time in/i }));

    await waitFor(() => expect(punchApi.punch).toHaveBeenCalledWith({ lat: 14.286, lng: 120.9345, accuracy: 18 }));
  });

  it('explains a denied location permission and never calls the API', async () => {
    mockGeolocation((_ok, err) => err({ code: 1, message: 'denied', PERMISSION_DENIED: 1 } as GeolocationPositionError));
    renderCard();

    fireEvent.click(await screen.findByRole('button', { name: /time in/i }));

    expect(await screen.findByText(/allow location access/i)).toBeInTheDocument();
    expect(punchApi.punch).not.toHaveBeenCalled();
  });

  it('shows the server reason when out of range', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.3, longitude: 120.95, accuracy: 10 } } as GeolocationPosition));
    vi.mocked(punchApi.punch).mockRejectedValue({ response: { status: 422, data: { message: 'You are 2600 m from the plant.', code: 'out_of_range' } } });
    renderCard();

    fireEvent.click(await screen.findByRole('button', { name: /time in/i }));

    expect(await screen.findByText(/2600 m from the plant/i)).toBeInTheDocument();
  });

  it('disables the button while the punch is pending', async () => {
    mockGeolocation((ok) => ok({ coords: { latitude: 14.286, longitude: 120.9345, accuracy: 18 } } as GeolocationPosition));
    vi.mocked(punchApi.punch).mockReturnValue(new Promise(() => {}));
    renderCard();

    const btn = await screen.findByRole('button', { name: /time in/i });
    fireEvent.click(btn);

    await waitFor(() => expect(screen.getByRole('button', { name: /locating|punching/i })).toBeDisabled());
  });
});
```

- [ ] **Step 2: Run to verify failure**

Run: `cd spa && npx vitest run src/components/self-service/TimeClockCard.test.tsx`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement**

Append to `spa/src/types/attendance.ts`:

```ts
export interface PunchStatus {
  next_action: 'in' | 'out';
  shift: { name: string; start: string; end: string } | null;
  punches: Array<{ direction: 'in' | 'out'; punched_at: string }>;
  tardiness_minutes: number | null;
  radius_m: number;
}

export interface GpsFix {
  lat: number;
  lng: number;
  accuracy: number;
}

export interface PunchResult {
  direction: 'in' | 'out';
  punched_at: string;
  distance_m: string;
  status: PunchStatus;
}

export interface AttendancePunchAudit {
  id: string;
  direction: 'in' | 'out';
  punched_at: string;
  latitude: string | null;
  longitude: string | null;
  accuracy_m: string;
  distance_m: string;
  ip: string | null;
}
```

`spa/src/api/attendance/punch.ts`:

```ts
import { client } from '../client';
import type { ApiSuccess } from '@/types';
import type { AttendancePunchAudit, GpsFix, PunchResult, PunchStatus } from '@/types/attendance';

export const punchApi = {
  status: () => client.get<ApiSuccess<PunchStatus>>('/attendance/punch/status').then((r) => r.data.data),
  punch: (fix: GpsFix) => client.post<ApiSuccess<PunchResult>>('/attendance/punch', fix).then((r) => r.data.data),
  audit: (attendanceId: string) =>
    client.get<{ data: AttendancePunchAudit[] }>(`/attendance/attendances/${attendanceId}/punches`).then((r) => r.data.data),
};
```

`TimeClockCard.tsx` — read two existing components first to match imports exactly: `sed -n 1,40p spa/src/components/ui/Panel.tsx` and `QueryErrorState.tsx` (props), then write:

```tsx
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { LuClock } from '@/lib/icons';
import { punchApi } from '@/api/attendance/punch';
import { useFeature } from '@/hooks/useFeature';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatTime } from '@/lib/formatDate';
import type { GpsFix } from '@/types/attendance';

const GEO_ERRORS: Record<number, string> = {
  1: 'Allow location access for this site in your browser settings, then try again.',
  2: 'Your location could not be determined. Move to an open area and try again.',
  3: 'Locating you took too long. Try again.',
};

function readFix(): Promise<GpsFix> {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new Error('This device cannot provide a location.'));
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      (e) => reject(new Error(GEO_ERRORS[e.code] ?? GEO_ERRORS[2])),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
  });
}

function serverMessage(error: unknown): string {
  const msg = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;
  return msg ?? (error instanceof Error ? error.message : 'Could not record your punch.');
}

export function TimeClockCard() {
  const enabled = useFeature('attendance');
  const qc = useQueryClient();
  const [notice, setNotice] = useState<string | null>(null);
  const [locating, setLocating] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance', 'punch', 'status'],
    queryFn: punchApi.status,
    enabled,
  });

  const punch = useMutation({
    mutationFn: async () => punchApi.punch(await readFix()),
    onMutate: () => {
      setNotice(null);
      setLocating(true);
    },
    onSuccess: (r) => {
      toast.success(r.direction === 'in' ? 'Timed in' : 'Timed out');
      qc.invalidateQueries({ queryKey: ['attendance'] });
      qc.invalidateQueries({ queryKey: ['self-service'] });
    },
    onError: (e) => {
      const message = serverMessage(e);
      setNotice(message);
      toast.error(message);
    },
    onSettled: () => setLocating(false),
  });

  if (!enabled) return null;

  const next = data?.next_action ?? 'in';
  const label = punch.isPending ? (locating ? 'Locating…' : 'Punching…') : next === 'in' ? 'Time In' : 'Time Out';

  return (
    <Panel title="Time In / Out">
      {isLoading && <SkeletonBlock className="h-16" />}
      {isError && <p className="text-sm text-danger">Could not load your time clock. Refresh to retry.</p>}
      {data && (
        <div className="flex flex-col gap-3">
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted">
              {data.shift ? `Shift ${data.shift.start}–${data.shift.end}` : 'No shift assigned'}
            </span>
            {data.tardiness_minutes ? <Chip variant="warning">Late by {data.tardiness_minutes} min</Chip> : null}
          </div>
          <Button
            variant="primary"
            size="lg"
            icon={<LuClock size={16} />}
            disabled={punch.isPending}
            onClick={() => punch.mutate()}
          >
            {label}
          </Button>
          {notice && <p role="alert" className="text-sm text-danger">{notice}</p>}
          {data.punches.length > 0 && (
            <ul className="text-xs font-mono tabular-nums text-muted">
              {data.punches.map((p) => (
                <li key={p.punched_at}>
                  {p.direction === 'in' ? 'In' : 'Out'} {formatTime(p.punched_at)}
                </li>
              ))}
            </ul>
          )}
          <p className="text-2xs text-muted">
            Your location is read only at the moment you punch, and only works within {data.radius_m} m of the plant.
          </p>
        </div>
      )}
    </Panel>
  );
}
```
Adjust prop names to the real component APIs if `tsc` complains (`Panel title`, `Button size/icon`, `Chip variant`, `SkeletonBlock className`); they match usage in `pages/self-service/index.tsx` and `dtr.tsx`.

In `pages/self-service/index.tsx` add `import { TimeClockCard } from '@/components/self-service/TimeClockCard';` and render `<TimeClockCard />` as the first child inside the page's root container (above the KPI `StatCard` row).

- [ ] **Step 4: Run to verify pass**

Run: `cd spa && npx vitest run src/components/self-service/TimeClockCard.test.tsx && npx tsc --noEmit && npm run audit:tokens`
Expected: PASS; no type errors; no hardcoded colours.

- [ ] **Step 5: Commit**

```bash
git add spa/src
git commit -m "feat: time in/out — self-service Time In/Out card with GPS

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: SPA — HR punch drawer, missing time-out filter, "use my current location"

**Files:**
- Create: `spa/src/components/attendance/PunchAuditModal.tsx`
- Modify: `spa/src/pages/attendance/index.tsx` (filter config ~line 428, row action, modal mount)
- Modify: `spa/src/api/attendance/attendances.ts` (`AttendanceListParams`: add `missing_time_out?: string`)
- Modify: `spa/src/pages/admin/settings.tsx` (`SettingsGroup`: company-group button)
- Test: `spa/src/components/attendance/PunchAuditModal.test.tsx`

**Interfaces:**
- Consumes: `punchApi.audit(attendanceId)` and `AttendancePunchAudit` (Task 8); `usePermission().can('attendance.punch.audit')`; existing `Modal` (`components/ui/Modal.tsx`), `FilterBar` select filter shape (`{ key, label, type: 'select', options }`), `settingsApi.update(key, value)`.
- Produces: `<PunchAuditModal attendanceId={string|null} onClose />`.

- [ ] **Step 1: Write the failing test**

```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { punchApi } from '@/api/attendance/punch';
import { PunchAuditModal } from './PunchAuditModal';

vi.mock('@/api/attendance/punch', () => ({ punchApi: { audit: vi.fn() } }));

const wrap = (ui: React.ReactNode) =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>);

describe('PunchAuditModal', () => {
  it('lists each punch with distance and shows a dash once coordinates are purged', async () => {
    vi.mocked(punchApi.audit).mockResolvedValue([
      { id: 'a', direction: 'in', punched_at: '2026-10-07T06:02:00+08:00', latitude: '14.286000', longitude: '120.934500', accuracy_m: '18.00', distance_m: '4.00', ip: '10.0.0.1' },
      { id: 'b', direction: 'out', punched_at: '2026-10-07T14:05:00+08:00', latitude: null, longitude: null, accuracy_m: '12.00', distance_m: '9.00', ip: null },
    ]);
    wrap(<PunchAuditModal attendanceId="x" onClose={() => {}} />);

    expect(await screen.findByText('14.286000, 120.934500')).toBeInTheDocument();
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    expect(screen.getByText(/4\.00 m/)).toBeInTheDocument();
  });

  it('shows an empty message when the day has no app punches', async () => {
    vi.mocked(punchApi.audit).mockResolvedValue([]);
    wrap(<PunchAuditModal attendanceId="x" onClose={() => {}} />);
    expect(await screen.findByText(/no app punches/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to verify failure**

Run: `cd spa && npx vitest run src/components/attendance/PunchAuditModal.test.tsx`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement**

`PunchAuditModal.tsx` (read `components/ui/Modal.tsx` props first and match them; the sketch assumes `isOpen`, `onClose`, `title`):

```tsx
import { useQuery } from '@tanstack/react-query';
import { punchApi } from '@/api/attendance/punch';
import { Modal } from '@/components/ui/Modal';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatTime } from '@/lib/formatDate';

interface Props {
  attendanceId: string | null;
  onClose: () => void;
}

export function PunchAuditModal({ attendanceId, onClose }: Props) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['attendance', 'punch-audit', attendanceId],
    queryFn: () => punchApi.audit(attendanceId as string),
    enabled: attendanceId !== null,
  });

  return (
    <Modal isOpen={attendanceId !== null} onClose={onClose} title="Time in/out punches">
      {isLoading && <SkeletonBlock className="h-24" />}
      {isError && <p className="text-sm text-danger">Could not load punches.</p>}
      {data && data.length === 0 && <p className="text-sm text-muted">No app punches for this day.</p>}
      {data && data.length > 0 && (
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left text-muted">
              <th>Type</th><th>Time</th><th>Distance</th><th>GPS ±</th><th>Location</th><th>IP</th>
            </tr>
          </thead>
          <tbody className="font-mono tabular-nums">
            {data.map((p) => (
              <tr key={p.id}>
                <td><Chip variant={p.direction === 'in' ? 'success' : 'info'}>{p.direction === 'in' ? 'In' : 'Out'}</Chip></td>
                <td>{formatTime(p.punched_at)}</td>
                <td>{p.distance_m} m</td>
                <td>{p.accuracy_m} m</td>
                <td>{p.latitude && p.longitude ? `${p.latitude}, ${p.longitude}` : '—'}</td>
                <td>{p.ip ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Modal>
  );
}
```
The empty-state test expects `—` only in the first test; the `—` for `ip: null` and purged coordinates covers it.

`pages/attendance/index.tsx`:
- add `missing_time_out?: string` to `AttendanceListParams` in `api/attendance/attendances.ts`;
- in `filterConfig` after the status select add `{ key: 'missing_time_out', label: 'Time out', type: 'select', options: [{ value: '', label: 'All' }, { value: '1', label: 'Missing time-out' }] }`;
- add `const [punchAuditId, setPunchAuditId] = useState<string | null>(null);`; for users with `can('attendance.punch.audit')` add a row action "Punches" calling `setPunchAuditId(row.id)` using the same row-action mechanism the page already uses for edit/archive (inspect `RowContextMenu` / the `actions` column at lines ~400–425 and add one entry in the same shape);
- mount `<PunchAuditModal attendanceId={punchAuditId} onClose={() => setPunchAuditId(null)} />` at the end of the page JSX.

`pages/admin/settings.tsx` — in `SettingsGroup`, when `group === 'company'` and `isEditMode`, render above the rows:

```tsx
{group === 'company' && isEditMode && (
  <div className="mb-3">
    <Button
      variant="secondary"
      size="sm"
      icon={<LuMapPin size={14} />}
      onClick={() =>
        navigator.geolocation.getCurrentPosition(
          (p) => {
            onSave('company.latitude', Number(p.coords.latitude.toFixed(6)));
            onSave('company.longitude', Number(p.coords.longitude.toFixed(6)));
          },
          () => toast.error('Could not read your location. Allow location access and try again.'),
          { enableHighAccuracy: true, timeout: 15000 },
        )
      }
    >
      Use my current location as the plant centre
    </Button>
    <p className="text-xs text-muted mt-1">Stand in the middle of the plant first. Time in/out uses this point.</p>
  </div>
)}
```
Import `LuMapPin` from `@/lib/icons` (if not exported there, add it following the file's existing re-export pattern from `lucide-react`), and `toast`/`Button` if not already imported in this file (`grep -n "^import" spa/src/pages/admin/settings.tsx`). Because `onSave` is per-key and each call saves independently, two sequential calls are acceptable; if `saving` state blocks the second call, call `settingsApi.update` directly for both and invalidate the settings query once.

- [ ] **Step 4: Run to verify pass**

Run: `cd spa && npx vitest run src/components/attendance src/pages/attendance src/pages/admin && npx tsc --noEmit && npm run audit:tokens`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add spa/src
git commit -m "feat: time in/out — HR punch audit modal, missing time-out filter, set plant centre button

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 10: End-to-end check, docs, full verification

**Files:**
- Create: `spa/e2e/time-clock.spec.ts`
- Modify: `docs/SCHEMA.md` (ATTENDANCE section: "5 tables" → "6 tables", add `attendance_punches`)
- Modify: `docs/superpowers/specs/2026-10-06-time-in-out-design.md` (apply the seven amendments listed at the top of this plan, if not already applied)

**Interfaces:**
- Consumes: `loginAs(page, 'employee', '/self-service')` from `e2e/helpers-extended.ts`; Playwright `context.grantPermissions(['geolocation'])` / `context.setGeolocation`.

- [ ] **Step 1: Write the e2e spec (Chromium, not Lightpanda)**

```ts
import { test, expect } from './fixtures';
import { loginAs } from './helpers-extended';

const STATUS = {
  next_action: 'in',
  shift: { name: 'Day', start: '06:00', end: '14:00' },
  punches: [],
  tardiness_minutes: null,
  radius_m: 200,
};

test.describe('Time in/out', () => {
  test('sends the browser GPS fix when the employee taps Time In', async ({ page, context }) => {
    await context.grantPermissions(['geolocation']);
    await context.setGeolocation({ latitude: 14.286, longitude: 120.9345, accuracy: 15 });

    let body: Record<string, number> | null = null;
    await page.route('**/api/v1/attendance/punch/status', (r) => r.fulfill({ json: { data: STATUS } }));
    await page.route('**/api/v1/attendance/punch', async (r) => {
      body = r.request().postDataJSON();
      await r.fulfill({ status: 201, json: { data: { direction: 'in', punched_at: new Date().toISOString(), distance_m: '3.00', status: { ...STATUS, next_action: 'out' } } } });
    });

    await loginAs(page, 'employee', '/self-service');
    await page.getByRole('button', { name: /time in/i }).click();

    await expect.poll(() => body).not.toBeNull();
    expect(body).toMatchObject({ lat: 14.286, lng: 120.9345 });
  });

  test('shows the server reason when out of range', async ({ page, context }) => {
    await context.grantPermissions(['geolocation']);
    await context.setGeolocation({ latitude: 14.5, longitude: 121.0, accuracy: 10 });

    await page.route('**/api/v1/attendance/punch/status', (r) => r.fulfill({ json: { data: STATUS } }));
    await page.route('**/api/v1/attendance/punch', (r) =>
      r.fulfill({ status: 422, json: { message: 'You are 26000 m from the plant. Time in/out works only within 200 m.', code: 'out_of_range' } }));

    await loginAs(page, 'employee', '/self-service');
    await page.getByRole('button', { name: /time in/i }).click();

    await expect(page.getByRole('alert')).toContainText('26000 m from the plant');
  });
});
```

- [ ] **Step 2: Run e2e**

Run: `cd spa && npx playwright test e2e/time-clock.spec.ts --project=chromium`
Expected: PASS (2 tests). If the project name differs, run `npx playwright test e2e/time-clock.spec.ts` and read `playwright.config.ts` for the Chromium project name.

- [ ] **Step 3: Docs**

`docs/SCHEMA.md` — change `## ATTENDANCE (5 tables)` to `(6 tables)` and append after the `holidays` block:

```
### attendance_punches
id, employee_id (FK employees), attendance_id (FK attendances nullable), direction (string 3: in/out), punched_at (timestamp), latitude (decimal 9,6 nullable — purged after 90 days), longitude (decimal 9,6 nullable — purged after 90 days), accuracy_m (decimal 7,2), distance_m (decimal 9,2), ip (string 45 nullable), user_agent (string 255 nullable), created_at, updated_at, INDEX (employee_id, punched_at), INDEX (attendance_id)
```

- [ ] **Step 4: Full verification**

Run (isolated DB): `docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test --filter='Punch|Attendance|SelfService|WidgetSeedIntegrity|ApprovalChainRolePermissionDrift|Settings'`
Run: `cd spa && npx vitest run && npx tsc --noEmit && npm run audit:tokens && npm run lint`
Expected: all PASS, 0 failures. Then run the whole API suite once on the isolated DB (`docker compose exec -T -e DB_DATABASE=ogami_test_punch api php artisan test`, ~28 min) and report any failure verbatim; do not mark done on a partial run.

Manual go-live check (cannot be automated): on a real phone over HTTPS, open `/self-service` at the plant, tap Time In, confirm it succeeds; stand outside the 200 m radius and confirm the hard block; as an admin standing in the middle of the plant, press "Use my current location as the plant centre" before go-live.

- [ ] **Step 5: Commit**

```bash
git add spa/e2e docs
git commit -m "feat: time in/out — e2e coverage and schema docs

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-review

**Spec coverage:** decisions 1 (GPS-only radius+accuracy) → T2; 2 (current-location button) → T9; 3 (hard block) → T2/T3; 4 (all employees, own session) → T3; 5 (window, shift resolution, no-shift block) → T2; 6 (server direction, cooldown) → T2; 7 (anchoring, 16 h, out-before-in) → T2; 8 (import merge, both paths) → T4; 9 (missing-time-out filter + daily notice, no auto-close) → T5/T6; 10 (locks, removed row, no employee) → T2/T3; 11 (table, 90-day purge, privacy notice) → T1/T6/T8; 12 (audit permission, drawer) → T1/T5/T9; 13 (card, state, punch list, late warning) → T8; 14 (nginx) → T7; testing section → T1–T10. Out-of-scope items are not built.
**Placeholder scan:** no TBD/TODO; two "confirm the real route/table name" steps (T1 settings URL, T6 notifications table) name the exact grep and what to change, because those two names were not read during planning.
**Type consistency:** `PunchService::punch/status` signatures, rejection codes, `PunchStatus`/`GpsFix`/`PunchResult`/`AttendancePunchAudit` types, and the `/attendance/punch*` paths are identical across T2, T3, T8, T9, T10.
