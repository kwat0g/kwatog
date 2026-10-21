# Pause / Extend Audit Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every finding from the 2026-10-17 Pause/Extend audit of Production Work Orders: downtime-row lifecycle on cancel/restoration/resume, pause notifications, release-reason audit, and the two SPA dialog gaps.

**Architecture:** Backend fixes are localized. One shared `closeOpenDowntimeRows()` helper on `WorkOrderService` is used by `resume()` and `cancel()`; `HandleMachineBreakdown::handleRestoration()` gets a scoped filter so it never closes a still-paused WO's downtime; a new `NotifyOnWorkOrderPaused` listener rides the existing `WorkOrderStatusChanged` outbox event (delivered via `DispatchOutboxCommand` → `event($event)`, like `HandleMachineBreakdown`); `releaseResources()` stops overwriting `pause_reason` and records an activity event instead. SPA: extension-reason textarea in `StartProductionDialog`, breakdown-category warning in the WO detail pause dialog.

**Tech Stack:** PHP 8.3 / Laravel 11 / PostgreSQL 16 / PHPUnit; React 18 / Vite / Vitest / TanStack Query / RTL.

**Spec:** The 2026-10-17 Pause/Extend audit (read-only audit session on branch `fix/ui-ux-wave1-shell`). Findings and evidence citations are reproduced below; treat them as the spec. Line numbers are audit-time pointers — re-read the surrounding code before editing.

## Findings Covered

| ID | Finding | Evidence (audit time) |
|---|---|---|
| H1 | `cancel()` never closes open `machine_downtimes` rows; `paused → cancelled` is legal, so a 0-output paused WO leaks a row that OEE counts up to `now` forever | `WorkOrderService.php:1051-1136`, `OeeService.php:149-152` |
| H2 | `handleRestoration()` closes ALL open downtime rows for the machine, including a still-paused WO's row | `HandleMachineBreakdown.php:271-283` |
| M1 | Extend reason is the hardcoded string `'Approved remaining production time extension.'`, yet it is the audited `execution_history.detail.reason` | `StartProductionDialog.tsx:147` |
| M2 | Pause dialog offers `breakdown` with no warning that it forces the machine into Breakdown and opens a corrective maintenance WO | `detail.tsx:1611-1660`, `WorkOrderService.php:624-632` |
| M3 | Manual pause/resume notifies nobody (only a top-10 dashboard alert); breakdown already has `NotifyOnMachineBreakdown` | `ProductionDashboardService.php:233-249` |
| M4 | `releaseResources()` overwrites `pause_reason` with the release reason, losing the stop cause | `WorkOrderService.php:655`, `ProductionResourceReservationTest.php:86` |
| L1 | `resume()` closes only the latest open downtime row, inconsistent with restoration's close-all | `WorkOrderService.php:771-780` |
| L2 | No regression tests for H1/H2; no coverage that legacy multi-row downtime closes on resume | audit — coverage check |

## Global Constraints

- Run backend tests inside Docker: `docker compose exec -T api php artisan test <paths>`. **Never run two PHPUnit commands concurrently** — both share `ogami_test` and `RefreshDatabase` tears the schema down under the other.
- SPA tests: `docker compose exec -T spa npm test -- --run <spec paths>`. Typecheck: `docker compose exec -T spa npm run typecheck`.
- `declare(strict_types=1);` at the top of every new PHP file.
- Migration naming: the settings seed uses the next free numbered prefix `0578_` (verified unused on 2026-10-17); the data repair uses a timestamped name (`2026_10_17_*`) because it reads data written by `2026_*` migrations.
- Stage only the files each task names. The working tree already carries unrelated modifications (`api/phpunit.xml`, `docker-compose.yml`, `docker/nginx/default.conf`, several `spa/` files). Never `git add -A`.
- Keep new mutations inside the existing `DB::transaction()` blocks. No new routes, no API contract changes.
- Pint on every touched PHP file before commit: `docker compose exec -T api ./vendor/bin/pint <files>`.

## Review Focus

These are the input classes most likely to bite in production; each has a test pinned in the owning task:

1. A WO paused for material shortage, whose machine later breaks down and is repaired while the WO stays paused — its downtime row must survive restoration and close only on resume/cancel (Task 3).
2. Cancel of a paused, zero-output, non-stock WO — must close the downtime row and still cancel (Task 1).
3. A breakdown pause must NOT double-notify `production_manager` (dedupe guard), while a material-shortage pause MUST notify (Task 4).
4. An extend submitted with an empty/whitespace reason — UI blocks it; backend already 422s (Task 6).
5. A refused resume (machine taken) must roll back its downtime close — existing invariant, re-verified after the helper refactor (Task 1 sweep step).

---

### Task 1: Close every open downtime row on resume and cancel (H1 + L1)

**Files:**
- Modify: `api/app/Modules/Production/Services/WorkOrderService.php` — add private `closeOpenDowntimeRows()`, call it in `resume()` (replacing the "Close any open downtime row" block around line 771) and in `cancel()` (right after the terminal `$lockedWo->update([...])` around line 1106, before `releaseReservedMaterials`).
- Test: `api/tests/Feature/Production/WorkOrderStartResumeOccupancyTest.php`

**Interfaces:**
- Produces: `private function closeOpenDowntimeRows(WorkOrder $wo): int` — closes every `MachineDowntime` row where `work_order_id = $wo->id` and `end_time IS NULL`, stamping `end_time = now()` and `duration_minutes = (int) max(0, start_time→now minutes)`, under `lockForUpdate()`. Returns the number closed. Private to `WorkOrderService`; no external consumers.
- Consumes: existing imports `MachineDowntime`, `Carbon` (already used at line 609).

- [ ] **Step 1: Write the failing tests**

Add to `WorkOrderStartResumeOccupancyTest` (helpers `machine()`, `mold()`, `startedWo()` already exist in the file):

```php
public function test_resume_closes_every_open_downtime_row_for_the_work_order(): void
{
    $machine = $this->machine();
    $mold = $this->mold();

    $wo = $this->startedWo($machine, $mold);
    $paused = $this->service->pause($wo, 'Material shortage', MachineDowntimeCategory::MaterialShortage);

    MachineDowntime::create([
        'machine_id' => $machine->id,
        'work_order_id' => $wo->id,
        'start_time' => now()->subMinutes(30),
        'category' => MachineDowntimeCategory::MaterialShortage->value,
        'description' => 'Legacy leaked row',
    ]);

    $this->service->resume($paused, $this->productionManager->id);

    $this->assertSame(0, MachineDowntime::where('work_order_id', $wo->id)->whereNull('end_time')->count(),
        'Resume must close every open downtime row for the work order.');
}

public function test_cancelling_a_paused_work_order_closes_its_open_downtime(): void
{
    $machine = $this->machine();
    $mold = $this->mold();

    $wo = $this->startedWo($machine, $mold);
    $paused = $this->service->pause($wo, 'Material shortage', MachineDowntimeCategory::MaterialShortage);

    $cancelled = $this->service->cancel($paused, 'Wrong product was started.');

    $this->assertSame(WorkOrderStatus::Cancelled, $cancelled->status);
    $this->assertSame(0, MachineDowntime::where('work_order_id', $wo->id)->whereNull('end_time')->count(),
        'Cancelling a paused work order must close its open downtime row.');
    $closed = MachineDowntime::where('work_order_id', $wo->id)->firstOrFail();
    $this->assertNotNull($closed->end_time);
    $this->assertNotNull($closed->duration_minutes);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T api php artisan test tests/Feature/Production/WorkOrderStartResumeOccupancyTest.php --filter='closes_every_open_downtime_row|cancelling_a_paused_work_order_closes'`
Expected: 2 failed — resume leaves the extra legacy row open; cancel leaves the pause row open.

- [ ] **Step 3: Implement the helper and both call sites**

Add to `WorkOrderService` (place near `resume()`):

```php
private function closeOpenDowntimeRows(WorkOrder $wo): int
{
    $now = Carbon::now();
    $rows = MachineDowntime::query()
        ->where('work_order_id', $wo->id)
        ->whereNull('end_time')
        ->lockForUpdate()
        ->get();

    foreach ($rows as $row) {
        $row->update([
            'end_time' => $now,
            'duration_minutes' => (int) max(0, $row->start_time->diffInMinutes($now, true)),
        ]);
    }

    return $rows->count();
}
```

In `resume()`, replace the existing "Close any open downtime row for this WO" block (`$open = MachineDowntime::where(...)->latest()->first(); if ($open) { ... }`) with:

```php
$this->closeOpenDowntimeRows($lockedWo);
```

In `cancel()`, immediately after the terminal `$lockedWo->update([... 'status' => WorkOrderStatus::Cancelled ...])` call, add:

```php
$this->closeOpenDowntimeRows($lockedWo);
```

- [ ] **Step 4: Run the file to verify green**

Run: `docker compose exec -T api php artisan test tests/Feature/Production/WorkOrderStartResumeOccupancyTest.php`
Expected: all pass — including the existing `test_resume_refused_when_another_work_order_took_the_machine_during_pause`, which pins that a refused resume rolls back its downtime close.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Production/Services/WorkOrderService.php api/tests/Feature/Production/WorkOrderStartResumeOccupancyTest.php
git commit -m "fix(production): close open downtime rows when a paused work order resumes or is cancelled"
```

---

### Task 2: Repair already-leaked downtime rows (H1 data)

**Files:**
- Create: `api/database/migrations/2026_10_17_120000_close_orphaned_open_machine_downtimes.php`

**Interfaces:**
- Consumes: `machine_downtimes.work_order_id` FK, `work_orders.status`/`deleted_at`.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Verify the filename is unused**

Run: `ls api/database/migrations | grep '2026_10_17'`
Expected: no output.

- [ ] **Step 2: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orphanWorkOrderIds = DB::table('work_orders')
            ->where('status', 'cancelled')
            ->orWhereNotNull('deleted_at')
            ->pluck('id');

        if ($orphanWorkOrderIds->isEmpty()) {
            return;
        }

        DB::table('machine_downtimes')
            ->whereNull('end_time')
            ->whereIn('work_order_id', $orphanWorkOrderIds)
            ->update([
                'end_time' => now(),
                'duration_minutes' => DB::raw('GREATEST(0, ROUND(EXTRACT(EPOCH FROM (now() - start_time)) / 60))'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data repair only; closed rows are not reopened.
    }
};
```

- [ ] **Step 3: Count leftovers before migrating**

Run:
```bash
docker compose exec -T db psql -U ogami -d ogami -c "SELECT count(*) FROM machine_downtimes md JOIN work_orders wo ON wo.id = md.work_order_id WHERE md.end_time IS NULL AND (wo.status = 'cancelled' OR wo.deleted_at IS NOT NULL);"
```
Record the number (may be 0 — that is fine).

- [ ] **Step 4: Run the migration and re-check**

Run: `docker compose exec -T api php artisan migrate`
Then re-run the count from Step 3. Expected: `0`.

- [ ] **Step 5: Prove the migration executes under `migrate:fresh`**

Run: `docker compose exec -T api php artisan test tests/Feature/Production/WorkOrderStartResumeOccupancyTest.php`
Expected: all pass (RefreshDatabase runs the new migration cleanly).

- [ ] **Step 6: Commit**

```bash
git add api/database/migrations/2026_10_17_120000_close_orphaned_open_machine_downtimes.php
git commit -m "fix(production): close orphaned open machine downtime rows for cancelled work orders"
```

---

### Task 3: Restoration must not close a still-paused WO's downtime (H2)

**Files:**
- Modify: `api/app/Modules/Production/Listeners/HandleMachineBreakdown.php` — `handleRestoration()` around lines 271-283, plus its class docblock line 38-40.
- Test: `api/tests/Feature/Production/MachineBreakdownLifecycleConcurrencyTest.php`

**Interfaces:**
- Consumes: `MachineDowntime::workOrder()` relation (exists), `WorkOrderStatus` enum (already imported in the listener).
- Produces: no signature changes; closure scope narrows to machine-level rows and rows of WOs that are not `paused`.

- [ ] **Step 1: Write the failing test**

```php
public function test_restoration_keeps_downtime_of_a_still_paused_work_order_open(): void
{
    Queue::fake();

    $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
    $pausedWorkOrder = WorkOrder::factory()->create([
        'machine_id' => $machine->id,
        'status' => WorkOrderStatus::Paused->value,
    ]);
    $woRow = MachineDowntime::create([
        'machine_id' => $machine->id,
        'work_order_id' => $pausedWorkOrder->id,
        'start_time' => now()->subMinutes(20),
        'category' => MachineDowntimeCategory::MaterialShortage->value,
        'description' => 'Still paused',
    ]);
    $machineRow = MachineDowntime::create([
        'machine_id' => $machine->id,
        'work_order_id' => null,
        'start_time' => now()->subMinutes(10),
        'category' => MachineDowntimeCategory::Breakdown->value,
        'description' => 'Machine breakdown',
    ]);

    app(HandleMachineBreakdown::class)->handle(new MachineStatusChanged(
        $machine->fresh(),
        MachineStatus::Breakdown->value,
        MachineStatus::Idle->value,
        'Restoration complete',
    ));

    $this->assertNull($woRow->fresh()->end_time,
        'A still-paused work order keeps its downtime open until resume or cancel.');
    $this->assertNotNull($machineRow->fresh()->end_time);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test tests/Feature/Production/MachineBreakdownLifecycleConcurrencyTest.php --filter=restoration_keeps_downtime`
Expected: FAIL — `$woRow` gets closed by the current close-all loop.

- [ ] **Step 3: Scope the close in `handleRestoration()`**

Replace the "Close any open downtime rows for this machine" loop with:

```php
MachineDowntime::where('machine_id', $machine->id)
    ->whereNull('end_time')
    ->with('workOrder:id,status')
    ->lockForUpdate()
    ->get()
    ->filter(fn (MachineDowntime $row): bool => $row->work_order_id === null
        || $row->workOrder?->status !== WorkOrderStatus::Paused)
    ->each(function ($row) use (&$closed): void {
        $end = now();
        $row->update([
            'end_time' => $end,
            'duration_minutes' => (int) max(0, $row->start_time->diffInMinutes($end, true)),
        ]);
        $closed++;
    });
```

Update the class docblock line that says "Close any open machine_downtimes row for the machine" to state the narrowed scope: a still-paused work order keeps its row open until resume or cancel.

- [ ] **Step 4: Run the whole test file to verify green**

Run: `docker compose exec -T api php artisan test tests/Feature/Production/MachineBreakdownLifecycleConcurrencyTest.php`
Expected: all pass — including `test_restoration_closes_open_machine_downtime_after_authoritative_transition` (machine-level row still closes).

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Production/Listeners/HandleMachineBreakdown.php api/tests/Feature/Production/MachineBreakdownLifecycleConcurrencyTest.php
git commit -m "fix(production): keep downtime rows of still-paused work orders open on machine restoration"
```

---

### Task 4: Notify on manual pause (M3)

**Files:**
- Create: `api/app/Modules/Production/Listeners/NotifyOnWorkOrderPaused.php`
- Modify: `api/app/Providers/AppServiceProvider.php` — add `Event::listen(WorkOrderStatusChanged::class, [NotifyOnWorkOrderPaused::class, 'handle']);` next to the existing `TriggerInProcessQC` line (~362) and the `use App\Modules\Production\Listeners\NotifyOnWorkOrderPaused;` import next to `NotifyOnMachineBreakdown` (~131).
- Create: `api/database/migrations/0578_seed_work_order_pause_notification_roles.php`
- Test: `api/tests/Feature/Notifications/WorkOrderPausedNotificationTest.php`

**Interfaces:**
- Consumes: `WorkOrderStatusChanged` (outbox-relayed via `DispatchOutboxCommand` → `event($event)`), `NotificationService::send($recipients, string $type, array $data)`, `SettingsService::get(string, mixed)`.
- Produces: notification type `production.wo_paused`; setting key `production.work_order_paused.notification_roles` seeded `['ppc_head', 'production_manager']`. Dedupe rule: skip when an open breakdown downtime row exists for the WO (breakdown already notifies via `NotifyOnMachineBreakdown`).

- [ ] **Step 1: Write the failing test**

`api/tests/Feature/Notifications/WorkOrderPausedNotificationTest.php`, mirroring `MachineBreakdownNotificationTest`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Listeners\NotifyOnWorkOrderPaused;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkOrderPausedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_pause_notifies_ppc_head_and_production_manager(): void
    {
        $ppc = $this->userWithRole('ppc_head');
        $pm = $this->userWithRole('production_manager');
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused->value]);

        app(NotifyOnWorkOrderPaused::class)->handle(
            new WorkOrderStatusChanged($wo, 'in_progress', 'paused', 'Material shortage'),
        );

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $ppc->id, 'type' => 'production.wo_paused']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $pm->id, 'type' => 'production.wo_paused']);
    }

    public function test_message_names_the_work_order_and_reason(): void
    {
        $this->userWithRole('ppc_head');
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused->value]);

        app(NotifyOnWorkOrderPaused::class)->handle(
            new WorkOrderStatusChanged($wo, 'in_progress', 'paused', 'Material shortage'),
        );

        $row = DB::table('notifications')->where('type', 'production.wo_paused')->first();
        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertStringContainsString($wo->wo_number, $data['title']);
        $this->assertStringContainsString('Material shortage', $data['message']);
    }

    public function test_non_pause_transitions_and_breakdown_pauses_do_not_notify(): void
    {
        $pm = $this->userWithRole('production_manager');
        $wo = WorkOrder::factory()->create(['status' => WorkOrderStatus::Paused->value]);
        MachineDowntime::create([
            'machine_id' => Machine::factory()->create()->id,
            'work_order_id' => $wo->id,
            'start_time' => now(),
            'category' => MachineDowntimeCategory::Breakdown->value,
            'description' => 'Breakdown',
        ]);

        $listener = app(NotifyOnWorkOrderPaused::class);
        $listener->handle(new WorkOrderStatusChanged($wo, 'paused', 'in_progress', null));
        $listener->handle(new WorkOrderStatusChanged($wo, 'in_progress', 'paused', 'Machine breakdown'));

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $pm->id)->count());
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T api php artisan test tests/Feature/Notifications/WorkOrderPausedNotificationTest.php`
Expected: FAIL — `NotifyOnWorkOrderPaused` class not found.

- [ ] **Step 3: Create the listener**

`api/app/Modules/Production/Listeners/NotifyOnWorkOrderPaused.php`, copying the `NotifyOnWorkOrderCompleted` shape (log + rethrow):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnWorkOrderPaused implements ShouldQueue
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function handle(WorkOrderStatusChanged $event): void
    {
        try {
            if ($event->to !== WorkOrderStatus::Paused->value) {
                return;
            }

            $wo = WorkOrder::query()->find($event->workOrder->id);
            if (! $wo || $wo->status !== WorkOrderStatus::Paused) {
                return;
            }

            $breakdownPause = MachineDowntime::query()
                ->where('work_order_id', $wo->id)
                ->where('category', MachineDowntimeCategory::Breakdown->value)
                ->whereNull('end_time')
                ->exists();
            if ($breakdownPause) {
                return;
            }

            $roles = array_values(array_filter((array) $this->settings->get('production.work_order_paused.notification_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->get();

            $reason = $event->reason ? " Reason: {$event->reason}." : '';

            $this->notifications->send($audience, 'production.wo_paused', [
                'title' => "Work Order {$wo->wo_number} Paused",
                'message' => "Production is stopped.{$reason}",
                'link_to' => "/production/work-orders/{$wo->hash_id}",
                'entity_type' => 'work_order',
                'entity_id' => $wo->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnWorkOrderPaused failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
```

- [ ] **Step 4: Wire the listener and seed the setting**

In `AppServiceProvider::boot()`, beside the `TriggerInProcessQC` listen line:

```php
Event::listen(WorkOrderStatusChanged::class, [NotifyOnWorkOrderPaused::class, 'handle']);
```

Migration `0578_seed_work_order_pause_notification_roles.php` (mirror `0373`):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'production.work_order_paused.notification_roles',
            'value' => json_encode(['ppc_head', 'production_manager']),
            'group' => 'production',
            'label' => 'Paused Work Order Notification Roles',
            'description' => 'Roles notified when a production work order is paused.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'production.work_order_paused.notification_roles')->delete();
    }
};
```

- [ ] **Step 5: Run tests to verify green**

Run: `docker compose exec -T api php artisan test tests/Feature/Notifications/WorkOrderPausedNotificationTest.php tests/Feature/Production/MachineBreakdownLifecycleConcurrencyTest.php`
Expected: all pass (breakdown path untouched).

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Production/Listeners/NotifyOnWorkOrderPaused.php api/app/Providers/AppServiceProvider.php api/database/migrations/0578_seed_work_order_pause_notification_roles.php api/tests/Feature/Notifications/WorkOrderPausedNotificationTest.php
git commit -m "feat(production): notify ppc_head and production_manager when a work order is paused"
```

---

### Task 5: Release resources keeps the pause cause, audits the release reason (M4)

**Files:**
- Modify: `api/app/Modules/Production/Services/WorkOrderService.php` — `releaseResources()` around line 648.
- Modify: `api/app/Modules/Production/Controllers/WorkOrderController.php` — `releaseResources()` around line 337.
- Test: `api/tests/Feature/Production/ProductionResourceReservationTest.php` — the assertion at line 86.

**Interfaces:**
- Produces: `public function releaseResources(WorkOrder $wo, string $reason, ?int $releasedBy = null): WorkOrder` — `pause_reason` untouched; `production_released_at` stamped; one activity event `type='production.work_order'`, `action='resources_released'`, `detail=['reason' => trim($reason)]`, `actorUserId=$releasedBy`.
- Existing callers keep compiling (`MaintenanceReadinessTest.php:161`, `WorkOrderCloseQualityGateTest.php:396`, `WorkOrderStartResumeOccupancyTest.php:151` pass no actor).

- [ ] **Step 1: Update the test to the new contract**

In `ProductionResourceReservationTest::test_future_confirmation_is_not_physical_ownership_and_pause_release_is_exact`, replace the line asserting the release reason lands in `pause_reason` (line 86) with:

```php
$this->assertNull($released->pause_reason, 'Releasing resources must not overwrite the pause cause.');
$this->assertDatabaseHas('activity_events', [
    'subject_id' => $wo->id,
    'type' => 'production.work_order',
    'action' => 'resources_released',
]);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T api php artisan test tests/Feature/Production/ProductionResourceReservationTest.php --filter=pause_release_is_exact`
Expected: FAIL — `pause_reason` currently holds the release reason and no activity event exists.

- [ ] **Step 3: Implement**

In `WorkOrderService::releaseResources()`, change the signature and the update block:

```php
public function releaseResources(WorkOrder $wo, string $reason, ?int $releasedBy = null): WorkOrder
```

Replace `$wo->update(['production_released_at' => now(), 'pause_reason' => $reason]);` with:

```php
$wo->update(['production_released_at' => now()]);
app(ActivityFeedService::class)->record('production.work_order', 'resources_released', $wo,
    'Paused resources released for '.$wo->wo_number.'.', ['reason' => trim($reason)], actorUserId: $releasedBy);
```

(`ActivityFeedService` is already imported in `WorkOrderService`.)

In `WorkOrderController::releaseResources()`:

```php
return new WorkOrderResource($this->service->releaseResources($workOrder, $data['reason'], $request->user()->id));
```

- [ ] **Step 4: Run the affected files to verify green**

Run: `docker compose exec -T api php artisan test tests/Feature/Production/ProductionResourceReservationTest.php tests/Feature/Maintenance/MaintenanceReadinessTest.php tests/Feature/Production/WorkOrderCloseQualityGateTest.php`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Production/Services/WorkOrderService.php api/app/Modules/Production/Controllers/WorkOrderController.php api/tests/Feature/Production/ProductionResourceReservationTest.php
git commit -m "fix(production): keep the pause cause when releasing resources and audit the release reason"
```

---

### Task 6: Extension reason from the operator (M1)

**Files:**
- Modify: `spa/src/pages/production/work-orders/StartProductionDialog.tsx`
- Test: `spa/src/pages/production/work-orders/StartProductionDialog.test.tsx`

**Interfaces:**
- Produces: extend payload `reason: extensionReason.trim()`; new state defaults to `'Approved remaining production time extension.'`; `Approve extension` disabled while the trimmed reason is empty. Backend contract unchanged (`reason` required, max 500 — already enforced by `ExtendWorkOrderRunRequest`).

- [ ] **Step 1: Write the failing tests**

In `StartProductionDialog.test.tsx` — extend the existing `'extends running production without starting it again'` test's `objectContaining` with `reason: 'Approved remaining production time extension.'`, then add:

```tsx
it('sends an operator-entered extension reason', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    start_mode: 'extend',
    execution_end: '2026-10-06T12:00:00+08:00',
    schedule: {
      id: 'slot',
      status: 'confirmed',
      updated_at: '2026-10-06T08:00:00+08:00',
      scheduled_start: '2026-10-06T08:00:00+08:00',
      scheduled_end: '2026-10-06T10:00:00+08:00',
      machine_id: 'machine',
      mold_id: 'mold',
    },
  });
  show('extend');
  fireEvent.change(await screen.findByLabelText(/Extension reason/), {
    target: { value: 'Machine slowed after mold change.' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Approve extension' }));
  await waitFor(() =>
    expect(workOrdersApi.extendRun).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({ reason: 'Machine slowed after mold change.' }),
    ),
  );
});

it('blocks the extension while the reason is blank', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    start_mode: 'extend',
    execution_end: '2026-10-06T12:00:00+08:00',
    schedule: {
      id: 'slot',
      status: 'confirmed',
      updated_at: '2026-10-06T08:00:00+08:00',
      scheduled_start: '2026-10-06T08:00:00+08:00',
      scheduled_end: '2026-10-06T10:00:00+08:00',
      machine_id: 'machine',
      mold_id: 'mold',
    },
  });
  show('extend');
  fireEvent.change(await screen.findByLabelText(/Extension reason/), { target: { value: '   ' } });
  expect(screen.getByRole('button', { name: 'Approve extension' })).toBeDisabled();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T spa npm test -- --run src/pages/production/work-orders/StartProductionDialog.test.tsx`
Expected: 2 new tests fail — no `Extension reason` field exists.

- [ ] **Step 3: Implement**

In `StartProductionDialog.tsx`:
1. Add the import: `import { Textarea } from '@/components/ui/Textarea';`
2. Add state beside the other `useState`s: `const [extensionReason, setExtensionReason] = useState('Approved remaining production time extension.');`
3. In the `execute` mutation's extend branch, replace `reason: 'Approved remaining production time extension.',` with `reason: extensionReason.trim(),`.
4. Render, right after the `{data.start_mode && data.start_mode !== 'scheduled' && data.execution_end && (...)}` block and before the late-start acknowledgement block:

```tsx
{action === 'extend' && (
  <Textarea
    label="Extension reason"
    required
    rows={3}
    maxLength={500}
    value={extensionReason}
    onChange={(event) => setExtensionReason(event.target.value)}
    placeholder="Why does the run need more time?"
    disabled={busy || readiness.isFetching}
  />
)}
```

5. In the `Approve extension` button's `disabled` expression, append ` || (action === 'extend' && !extensionReason.trim())`.

- [ ] **Step 4: Run tests and typecheck**

Run: `docker compose exec -T spa npm test -- --run src/pages/production/work-orders/StartProductionDialog.test.tsx && docker compose exec -T spa npm run typecheck`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add spa/src/pages/production/work-orders/StartProductionDialog.tsx spa/src/pages/production/work-orders/StartProductionDialog.test.tsx
git commit -m "feat(production): capture an operator extension reason before approving more run time"
```

---

### Task 7: Breakdown-category warning in the pause dialog (M2)

**Files:**
- Modify: `spa/src/pages/production/work-orders/detail.tsx` — pause dialog around lines 1611-1660.
- Test: `spa/src/pages/production/work-orders/detail.test.tsx`

**Interfaces:**
- Consumes: existing `pauseCategory` state and `downtimeCategories` query in `detail.tsx`.
- Produces: nothing downstream.

- [ ] **Step 1: Write the failing test**

Add a new `describe` to `detail.test.tsx`, following the existing harness (module mocks and `permissions` are already set up at the top of the file):

```tsx
describe('pause downtime category guidance', () => {
  afterEach(() => { permissions.only = null; permissions.allow = false; });

  it('warns that a breakdown pause moves the machine into breakdown and opens maintenance', async () => {
    permissions.only = ['production.work_orders.view', 'production.work_orders.lifecycle'];
    vi.mocked(workOrdersApi.show).mockResolvedValue({ ...workOrder, status: 'in_progress' });
    vi.mocked(workOrdersApi.downtimeCategories).mockResolvedValue([
      { value: 'breakdown', label: 'Breakdown', is_planned: false },
      { value: 'material_shortage', label: 'Material shortage', is_planned: false },
    ]);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const view = render(
      <QueryClientProvider client={client}>
        <MemoryRouter><WorkOrderDetailPage /></MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Pause' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/Downtime category/), { target: { value: 'breakdown' } });
    expect(within(dialog).getByText(/corrective maintenance/)).toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText(/Downtime category/), { target: { value: 'material_shortage' } });
    expect(within(dialog).queryByText(/corrective maintenance/)).toBeNull();
    view.unmount();
  });
});
```

If the mocked value's shape disagrees with the `MachineDowntimeCategoryOption` type, mirror that type exactly rather than casting.

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T spa npm test -- --run src/pages/production/work-orders/detail.test.tsx`
Expected: the new test fails — no warning text exists.

- [ ] **Step 3: Implement**

In the pause dialog, directly after the `Select` (Downtime category) and before the `Textarea`:

```tsx
{pauseCategory === 'breakdown' && (
  <p className="text-xs text-warning-fg">
    Breakdown moves the machine into Breakdown and opens a corrective maintenance order. This
    work order can only resume after the machine is repaired and released.
  </p>
)}
```

- [ ] **Step 4: Run tests and typecheck**

Run: `docker compose exec -T spa npm test -- --run src/pages/production/work-orders/detail.test.tsx && docker compose exec -T spa npm run typecheck`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add spa/src/pages/production/work-orders/detail.tsx spa/src/pages/production/work-orders/detail.test.tsx
git commit -m "feat(production): warn about machine-side effects when pausing with the breakdown category"
```

---

### Task 8: Regression sweep

**Files:** none (verification only).

- [ ] **Step 1: Backend sweep (single run — never parallel)**

Run:
```bash
docker compose exec -T api php artisan test \
  tests/Feature/Production/WorkOrderStartResumeOccupancyTest.php \
  tests/Feature/Production/MachineBreakdownLifecycleConcurrencyTest.php \
  tests/Feature/Production/ProductionResourceReservationTest.php \
  tests/Feature/Production/WorkOrderExecutionReadinessTest.php \
  tests/Feature/Production/StockWorkOrderCancellationTest.php \
  tests/Feature/Production/WorkOrderCloseQualityGateTest.php \
  tests/Feature/Production/WorkOrderTrialRoutesTest.php \
  tests/Feature/Notifications/WorkOrderPausedNotificationTest.php \
  tests/Feature/Maintenance/MaintenanceReadinessTest.php \
  tests/Feature/Maintenance/MaintenanceWorkOrderMachineDowntimeTest.php
```
Expected: all pass. Note: `ProductionAuditHardeningTest` has pre-existing env-flag-dependent failures (unrelated); do not chase them.

- [ ] **Step 2: SPA sweep**

Run:
```bash
docker compose exec -T spa npm test -- --run src/pages/production/work-orders/detail.test.tsx src/pages/production/work-orders/StartProductionDialog.test.tsx
docker compose exec -T spa npm run typecheck
```
Expected: all pass.

- [ ] **Step 3: Pint and diff hygiene**

Run: `docker compose exec -T api ./vendor/bin/pint app/Modules/Production/Services/WorkOrderService.php app/Modules/Production/Controllers/WorkOrderController.php app/Modules/Production/Listeners/HandleMachineBreakdown.php app/Modules/Production/Listeners/NotifyOnWorkOrderPaused.php app/Providers/AppServiceProvider.php database/migrations/0578_seed_work_order_pause_notification_roles.php database/migrations/2026_10_17_120000_close_orphaned_open_machine_downtimes.php`
Then: `git status --short` — only the files named in Tasks 1-7 are modified/added by this work; pre-existing unrelated modifications remain untouched.

- [ ] **Step 4: Report**

Summarize: findings closed, test counts, the dedupe rule (breakdown pauses notify only via `NotifyOnMachineBreakdown`), and the out-of-scope items below.

---

## Out of Scope (decided during planning)

- **Shop-floor pause authority** (audit open question): whether recorders/operators should be able to pause from the floor PWA is a product decision, not a defect. Needs a stakeholder answer before any code.
- **Offline-machine breakdown pause 409**: verified unreachable — a machine owning an `in_progress` WO can only be `running` (acquire sets `running`; `MachineService::transitionStatus` refuses idle/maintenance while ownership is set), so `offline → breakdown` cannot be requested for a live WO. No code.
- **Cancel overwriting `pause_reason`**: intentional and pinned by `StockWorkOrderCancellationTest.php:52` (the terminal reason shown after cancellation). Not changed.
