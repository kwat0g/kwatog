# Notification Delivery Triggers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire notification delivery to every meaningful business event so the right person is always told when something requires their attention or has changed — covering approval requests, approval outcomes, production completions, quality failures, inventory alerts, and machine breakdowns.

**Architecture:** Each notification is triggered by an event listener using the existing `NotificationService::send()` pattern. Services fire domain events at status transitions; listeners resolve recipients by role and call `send()`. New notification type strings are registered in the frontend preferences constant. No new infrastructure — pure extension of the established listener pattern.

**Tech Stack:** Laravel 11 events/listeners, `NotificationService::send()`, `AppServiceProvider::boot()` for registration, React + TypeScript for preferences constant update.

---

## Coverage Map (what already exists vs what this plan adds)

| Event | Already notifies | This plan adds |
|---|---|---|
| SO confirmed | PPC Head, Prod Manager ✅ | — |
| PR approved | PR audience ✅ | — |
| PO approved | PO audience ✅ | — |
| Delivery confirmed | Finance ✅ | — |
| Payroll finalized | All employees ✅ | — |
| Separation initiated | HR team ✅ | — |
| Approval reminder (24h) | Pending approver ✅ | — |
| Approval escalation (48h) | Approver + superior ✅ | — |
| **Leave submitted** | ❌ | Dept Head |
| **Leave pending HR** | ❌ | HR Officer |
| **Leave approved** | ❌ | Requesting employee |
| **Leave rejected** | ❌ | Requesting employee |
| **OT submitted** | ❌ | Dept Head |
| **OT approved/rejected** | ❌ | Requesting employee |
| **Loan submitted** | ❌ | Finance Officer |
| **Loan approved/rejected** | ❌ | Requesting employee |
| **Work Order completed** | ❌ | PPC Head, Prod Manager |
| **QC inspection failed** | ❌ | Prod Manager, QC Inspector |
| **GRN received** | ❌ | Purchasing Officer |
| **Machine breakdown** | ❌ | Maintenance Tech, Prod Manager |
| **Low stock auto-PR created** | ❌ | Purchasing Officer |

---

## File Structure

**New files (Events):**
- `api/app/Modules/Leave/Events/LeaveRequestSubmitted.php`
- `api/app/Modules/Leave/Events/LeaveRequestPendingHR.php`
- `api/app/Modules/Leave/Events/LeaveRequestApproved.php`
- `api/app/Modules/Leave/Events/LeaveRequestRejected.php`
- `api/app/Modules/Attendance/Events/OvertimeRequestSubmitted.php`
- `api/app/Modules/Attendance/Events/OvertimeRequestDecided.php`
- `api/app/Modules/Loans/Events/LoanSubmitted.php`
- `api/app/Modules/Loans/Events/LoanDecided.php`

**New files (Listeners):**
- `api/app/Modules/Leave/Listeners/NotifyOnLeaveSubmitted.php`
- `api/app/Modules/Leave/Listeners/NotifyOnLeavePendingHR.php`
- `api/app/Modules/Leave/Listeners/NotifyOnLeaveDecided.php`
- `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeSubmitted.php`
- `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php`
- `api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php`
- `api/app/Modules/Loans/Listeners/NotifyOnLoanDecided.php`
- `api/app/Modules/Production/Listeners/NotifyOnWorkOrderCompleted.php`
- `api/app/Modules/Quality/Listeners/NotifyOnInspectionFailed.php`
- `api/app/Modules/Inventory/Listeners/NotifyOnGrnReceived.php`
- `api/app/Modules/Production/Listeners/NotifyOnMachineBreakdown.php`
- `api/app/Modules/Inventory/Listeners/NotifyOnLowStockPrCreated.php`

**Modified files:**
- `api/app/Modules/Leave/Services/LeaveRequestService.php` — fire events
- `api/app/Modules/Attendance/Services/OvertimeService.php` — fire events
- `api/app/Modules/Loans/Services/LoanService.php` — fire events
- `api/app/Providers/AppServiceProvider.php` — register 11 new listeners
- `spa/src/pages/self-service/notification-preferences.tsx` — add new types to NOTIFICATION_TYPES

---

### Task 1: Leave request notifications

Adds notifications at all four leave lifecycle transitions: submitted → dept head, pending HR → HR officer, approved → employee, rejected → employee.

**Files:**
- Create: `api/app/Modules/Leave/Events/LeaveRequestSubmitted.php`
- Create: `api/app/Modules/Leave/Events/LeaveRequestPendingHR.php`
- Create: `api/app/Modules/Leave/Events/LeaveRequestApproved.php`
- Create: `api/app/Modules/Leave/Events/LeaveRequestRejected.php`
- Create: `api/app/Modules/Leave/Listeners/NotifyOnLeaveSubmitted.php`
- Create: `api/app/Modules/Leave/Listeners/NotifyOnLeavePendingHR.php`
- Create: `api/app/Modules/Leave/Listeners/NotifyOnLeaveDecided.php`
- Modify: `api/app/Modules/Leave/Services/LeaveRequestService.php`
- Modify: `api/app/Providers/AppServiceProvider.php`

- [ ] **Step 1.1: Create the four Leave events**

```php
// api/app/Modules/Leave/Events/LeaveRequestSubmitted.php
<?php
declare(strict_types=1);
namespace App\Modules\Leave\Events;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class LeaveRequestSubmitted
{
    use Dispatchable, SerializesModels;
    public function __construct(public LeaveRequest $leaveRequest) {}
}
```

```php
// api/app/Modules/Leave/Events/LeaveRequestPendingHR.php
<?php
declare(strict_types=1);
namespace App\Modules\Leave\Events;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class LeaveRequestPendingHR
{
    use Dispatchable, SerializesModels;
    public function __construct(public LeaveRequest $leaveRequest) {}
}
```

```php
// api/app/Modules/Leave/Events/LeaveRequestApproved.php
<?php
declare(strict_types=1);
namespace App\Modules\Leave\Events;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class LeaveRequestApproved
{
    use Dispatchable, SerializesModels;
    public function __construct(public LeaveRequest $leaveRequest) {}
}
```

```php
// api/app/Modules/Leave/Events/LeaveRequestRejected.php
<?php
declare(strict_types=1);
namespace App\Modules\Leave\Events;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class LeaveRequestRejected
{
    use Dispatchable, SerializesModels;
    public function __construct(public LeaveRequest $leaveRequest) {}
}
```

- [ ] **Step 1.2: Create the three Leave listeners**

```php
// api/app/Modules/Leave/Listeners/NotifyOnLeaveSubmitted.php
<?php
declare(strict_types=1);
namespace App\Modules\Leave\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnLeaveSubmitted implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(LeaveRequestSubmitted $event): void
    {
        try {
            $req = $event->leaveRequest->loadMissing(['employee', 'leaveType']);
            $emp = $req->employee;
            $audience = User::whereHas('role', fn ($q) => $q->where('slug', 'department_head'))
                ->where('is_active', true)
                ->get();
            $this->notifications->send($audience, 'leave.submitted', [
                'title'       => "Leave Request from {$emp->full_name}",
                'message'     => "{$req->leaveType?->name} — {$req->days} day(s) from {$req->start_date->format('M j')} to {$req->end_date->format('M j')}.",
                'link_to'     => "/hr/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeaveSubmitted failed', ['error' => $e->getMessage()]);
        }
    }
}
```

```php
// api/app/Modules/Leave/Listeners/NotifyOnLeavePendingHR.php
<?php
declare(strict_types=1);
namespace App\Modules\Leave\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnLeavePendingHR implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(LeaveRequestPendingHR $event): void
    {
        try {
            $req = $event->leaveRequest->loadMissing(['employee', 'leaveType']);
            $emp = $req->employee;
            $audience = User::whereHas('role', fn ($q) => $q->where('slug', 'hr_officer'))
                ->where('is_active', true)
                ->get();
            $this->notifications->send($audience, 'leave.pending_hr', [
                'title'       => "Leave Needs HR Approval — {$emp->full_name}",
                'message'     => "{$req->leaveType?->name} — {$req->days} day(s). Dept head approved.",
                'link_to'     => "/hr/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeavePendingHR failed', ['error' => $e->getMessage()]);
        }
    }
}
```

```php
// api/app/Modules/Leave/Listeners/NotifyOnLeaveDecided.php
<?php
declare(strict_types=1);
namespace App\Modules\Leave\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnLeaveDecided implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handleApproved(LeaveRequestApproved $event): void
    {
        try {
            $req  = $event->leaveRequest->loadMissing(['employee.user', 'leaveType']);
            $user = $req->employee?->user;
            if (! $user) return;
            $this->notifications->send($user, 'leave.approved', [
                'title'       => 'Leave Request Approved',
                'message'     => "Your {$req->leaveType?->name} ({$req->days} day(s)) from {$req->start_date->format('M j')} has been approved.",
                'link_to'     => "/self-service/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeaveDecided::approved failed', ['error' => $e->getMessage()]);
        }
    }
    public function handleRejected(LeaveRequestRejected $event): void
    {
        try {
            $req  = $event->leaveRequest->loadMissing(['employee.user', 'leaveType']);
            $user = $req->employee?->user;
            if (! $user) return;
            $this->notifications->send($user, 'leave.rejected', [
                'title'       => 'Leave Request Rejected',
                'message'     => "Your {$req->leaveType?->name} ({$req->days} day(s)) from {$req->start_date->format('M j')} was not approved.",
                'link_to'     => "/self-service/leaves/{$req->hash_id}",
                'entity_type' => 'leave_request',
                'entity_id'   => $req->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLeaveDecided::rejected failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 1.3: Fire events from LeaveRequestService**

In `api/app/Modules/Leave/Services/LeaveRequestService.php`, add event dispatches at each transition. Add these `use` imports at the top:

```php
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use App\Modules\Leave\Events\LeaveRequestRejected;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
```

In `create()`, after `return $req->load(...)`:
```php
// fire before return
event(new LeaveRequestSubmitted($req));
return $req->load(['employee', 'leaveType']);
```

In `approveDept()`, after `$req->update([...])`:
```php
event(new LeaveRequestPendingHR($req->fresh(['employee', 'leaveType'])));
```

In `approveHR()`, after `$this->markAttendance($req)`:
```php
event(new LeaveRequestApproved($req->fresh(['employee', 'leaveType'])));
```

In `reject()`, after the update that sets status to Rejected, before the `return`:
```php
event(new LeaveRequestRejected($req->fresh(['employee', 'leaveType'])));
```

- [ ] **Step 1.4: Register listeners in AppServiceProvider**

In `api/app/Providers/AppServiceProvider.php`, add these imports and registrations:

```php
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use App\Modules\Leave\Events\LeaveRequestRejected;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
use App\Modules\Leave\Listeners\NotifyOnLeaveDecided;
use App\Modules\Leave\Listeners\NotifyOnLeavePendingHR;
use App\Modules\Leave\Listeners\NotifyOnLeaveSubmitted;
```

Inside `boot()`, in the existing events block:
```php
Event::listen(LeaveRequestSubmitted::class, [NotifyOnLeaveSubmitted::class, 'handle']);
Event::listen(LeaveRequestPendingHR::class, [NotifyOnLeavePendingHR::class, 'handle']);
Event::listen(LeaveRequestApproved::class,  [NotifyOnLeaveDecided::class, 'handleApproved']);
Event::listen(LeaveRequestRejected::class,  [NotifyOnLeaveDecided::class, 'handleRejected']);
```

- [ ] **Step 1.5: Write feature test**

Create `api/tests/Feature/Notifications/LeaveNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Notifications;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Events\LeaveRequestApproved;
use App\Modules\Leave\Events\LeaveRequestPendingHR;
use App\Modules\Leave\Events\LeaveRequestRejected;
use App\Modules\Leave\Events\LeaveRequestSubmitted;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LeaveNotificationTest extends TestCase
{
    public function test_leave_submitted_fires_event(): void
    {
        Event::fake([LeaveRequestSubmitted::class]);
        $req = LeaveRequest::factory()->pendingDept()->create();
        Event::assertDispatched(LeaveRequestSubmitted::class, fn ($e) => $e->leaveRequest->is($req));
    }

    public function test_leave_dept_approved_fires_pending_hr_event(): void
    {
        Event::fake([LeaveRequestPendingHR::class]);
        $req     = LeaveRequest::factory()->pendingDept()->create();
        $deptHead = $this->userWithRole('department_head');
        // call approveDept directly
        app(\App\Modules\Leave\Services\LeaveRequestService::class)->approveDept($req, $deptHead);
        Event::assertDispatched(LeaveRequestPendingHR::class);
    }

    public function test_leave_hr_approved_fires_approved_event(): void
    {
        Event::fake([LeaveRequestApproved::class]);
        $req    = LeaveRequest::factory()->pendingHR()->create();
        $hrUser = $this->userWithRole('hr_officer');
        app(\App\Modules\Leave\Services\LeaveRequestService::class)->approveHR($req, $hrUser);
        Event::assertDispatched(LeaveRequestApproved::class);
    }

    public function test_leave_rejected_fires_rejected_event(): void
    {
        Event::fake([LeaveRequestRejected::class]);
        $req    = LeaveRequest::factory()->pendingDept()->create();
        $deptHead = $this->userWithRole('department_head');
        app(\App\Modules\Leave\Services\LeaveRequestService::class)->reject($req, $deptHead, 'Insufficient leave balance.');
        Event::assertDispatched(LeaveRequestRejected::class);
    }

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', $slug)->firstOrFail();
        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 1.6: Run tests**

```bash
cd /home/kwat0g/Desktop/kwatog && php artisan test --filter=LeaveNotificationTest
```

Expected: all 4 tests pass.

- [ ] **Step 1.7: Commit**

```bash
git add api/app/Modules/Leave/Events/ \
        api/app/Modules/Leave/Listeners/Notify*.php \
        api/app/Modules/Leave/Services/LeaveRequestService.php \
        api/app/Providers/AppServiceProvider.php \
        api/tests/Feature/Notifications/LeaveNotificationTest.php
git commit -m "feat(notifications): leave request lifecycle notifications"
```

---

### Task 2: Overtime request notifications

Adds notifications when OT is submitted (→ dept head) and when approved or rejected (→ requesting employee).

**Files:**
- Create: `api/app/Modules/Attendance/Events/OvertimeRequestSubmitted.php`
- Create: `api/app/Modules/Attendance/Events/OvertimeRequestDecided.php`
- Create: `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeSubmitted.php`
- Create: `api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php`
- Modify: `api/app/Modules/Attendance/Services/OvertimeService.php`
- Modify: `api/app/Providers/AppServiceProvider.php`

- [ ] **Step 2.1: Create OT events**

```php
// api/app/Modules/Attendance/Events/OvertimeRequestSubmitted.php
<?php
declare(strict_types=1);
namespace App\Modules\Attendance\Events;
use App\Modules\Attendance\Models\OvertimeRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class OvertimeRequestSubmitted
{
    use Dispatchable, SerializesModels;
    public function __construct(public OvertimeRequest $overtimeRequest) {}
}
```

```php
// api/app/Modules/Attendance/Events/OvertimeRequestDecided.php
<?php
declare(strict_types=1);
namespace App\Modules\Attendance\Events;
use App\Modules\Attendance\Models\OvertimeRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class OvertimeRequestDecided
{
    use Dispatchable, SerializesModels;
    public function __construct(
        public OvertimeRequest $overtimeRequest,
        public bool $approved,
    ) {}
}
```

- [ ] **Step 2.2: Create OT listeners**

```php
// api/app/Modules/Attendance/Listeners/NotifyOnOvertimeSubmitted.php
<?php
declare(strict_types=1);
namespace App\Modules\Attendance\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnOvertimeSubmitted implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(OvertimeRequestSubmitted $event): void
    {
        try {
            $ot  = $event->overtimeRequest->loadMissing('employee');
            $emp = $ot->employee;
            $audience = User::whereHas('role', fn ($q) => $q->where('slug', 'department_head'))
                ->where('is_active', true)
                ->get();
            $this->notifications->send($audience, 'attendance.ot_submitted', [
                'title'       => "OT Request from {$emp->full_name}",
                'message'     => "{$ot->hours_requested}h on {$ot->date->format('M j, Y')}.",
                'link_to'     => "/hr/attendance/overtime/{$ot->hash_id}",
                'entity_type' => 'overtime_request',
                'entity_id'   => $ot->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnOvertimeSubmitted failed', ['error' => $e->getMessage()]);
        }
    }
}
```

```php
// api/app/Modules/Attendance/Listeners/NotifyOnOvertimeDecided.php
<?php
declare(strict_types=1);
namespace App\Modules\Attendance\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnOvertimeDecided implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(OvertimeRequestDecided $event): void
    {
        try {
            $ot   = $event->overtimeRequest->loadMissing('employee.user');
            $user = $ot->employee?->user;
            if (! $user) return;
            $type  = $event->approved ? 'attendance.ot_approved' : 'attendance.ot_rejected';
            $label = $event->approved ? 'Approved' : 'Rejected';
            $this->notifications->send($user, $type, [
                'title'       => "Overtime Request {$label}",
                'message'     => "Your OT request ({$ot->hours_requested}h on {$ot->date->format('M j')}) was {$label}.",
                'link_to'     => "/self-service/overtime/{$ot->hash_id}",
                'entity_type' => 'overtime_request',
                'entity_id'   => $ot->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnOvertimeDecided failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 2.3: Fire events from OvertimeService**

In `api/app/Modules/Attendance/Services/OvertimeService.php`, add imports:

```php
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
```

In `create()` (the method that persists a new OT request), after the OT is saved:
```php
event(new OvertimeRequestSubmitted($ot));
```

In `approve()`, before the `return`:
```php
event(new OvertimeRequestDecided($ot, true));
```

In `reject()`, before the `return`:
```php
event(new OvertimeRequestDecided($ot, false));
```

- [ ] **Step 2.4: Register listeners in AppServiceProvider**

Add imports:
```php
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use App\Modules\Attendance\Listeners\NotifyOnOvertimeDecided;
use App\Modules\Attendance\Listeners\NotifyOnOvertimeSubmitted;
```

Inside `boot()`:
```php
Event::listen(OvertimeRequestSubmitted::class, [NotifyOnOvertimeSubmitted::class, 'handle']);
Event::listen(OvertimeRequestDecided::class,   [NotifyOnOvertimeDecided::class,   'handle']);
```

- [ ] **Step 2.5: Write feature test**

Create `api/tests/Feature/Notifications/OvertimeNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Notifications;
use App\Modules\Attendance\Events\OvertimeRequestDecided;
use App\Modules\Attendance\Events\OvertimeRequestSubmitted;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OvertimeNotificationTest extends TestCase
{
    public function test_ot_submitted_fires_event(): void
    {
        Event::fake([OvertimeRequestSubmitted::class]);
        $ot = \App\Modules\Attendance\Models\OvertimeRequest::factory()->pending()->create();
        Event::assertDispatched(OvertimeRequestSubmitted::class, fn ($e) => $e->overtimeRequest->is($ot));
    }

    public function test_ot_approved_fires_decided_event(): void
    {
        Event::fake([OvertimeRequestDecided::class]);
        $ot       = \App\Modules\Attendance\Models\OvertimeRequest::factory()->pending()->create();
        $approver = $this->userWithRole('department_head');
        app(\App\Modules\Attendance\Services\OvertimeService::class)->approve($ot, $approver);
        Event::assertDispatched(OvertimeRequestDecided::class, fn ($e) => $e->approved === true);
    }

    public function test_ot_rejected_fires_decided_event(): void
    {
        Event::fake([OvertimeRequestDecided::class]);
        $ot       = \App\Modules\Attendance\Models\OvertimeRequest::factory()->pending()->create();
        $approver = $this->userWithRole('department_head');
        app(\App\Modules\Attendance\Services\OvertimeService::class)->reject($ot, $approver, 'No budget.');
        Event::assertDispatched(OvertimeRequestDecided::class, fn ($e) => $e->approved === false);
    }

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', $slug)->firstOrFail();
        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 2.6: Run tests**

```bash
php artisan test --filter=OvertimeNotificationTest
```

Expected: 3 tests pass.

- [ ] **Step 2.7: Commit**

```bash
git add api/app/Modules/Attendance/Events/ \
        api/app/Modules/Attendance/Listeners/Notify*.php \
        api/app/Modules/Attendance/Services/OvertimeService.php \
        api/app/Providers/AppServiceProvider.php \
        api/tests/Feature/Notifications/OvertimeNotificationTest.php
git commit -m "feat(notifications): overtime request submission and decision notifications"
```

---

### Task 3: Loan request notifications

Adds notifications when a loan/cash advance is submitted (→ finance officer) and when approved or rejected (→ requesting employee).

**Files:**
- Create: `api/app/Modules/Loans/Events/LoanSubmitted.php`
- Create: `api/app/Modules/Loans/Events/LoanDecided.php`
- Create: `api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php`
- Create: `api/app/Modules/Loans/Listeners/NotifyOnLoanDecided.php`
- Modify: `api/app/Modules/Loans/Services/LoanService.php`
- Modify: `api/app/Providers/AppServiceProvider.php`

- [ ] **Step 3.1: Create Loan events**

```php
// api/app/Modules/Loans/Events/LoanSubmitted.php
<?php
declare(strict_types=1);
namespace App\Modules\Loans\Events;
use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class LoanSubmitted
{
    use Dispatchable, SerializesModels;
    public function __construct(public EmployeeLoan $loan) {}
}
```

```php
// api/app/Modules/Loans/Events/LoanDecided.php
<?php
declare(strict_types=1);
namespace App\Modules\Loans\Events;
use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class LoanDecided
{
    use Dispatchable, SerializesModels;
    public function __construct(
        public EmployeeLoan $loan,
        public bool $approved,
    ) {}
}
```

- [ ] **Step 3.2: Create Loan listeners**

```php
// api/app/Modules/Loans/Listeners/NotifyOnLoanSubmitted.php
<?php
declare(strict_types=1);
namespace App\Modules\Loans\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Loans\Events\LoanSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnLoanSubmitted implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(LoanSubmitted $event): void
    {
        try {
            $loan = $event->loan->loadMissing('employee');
            $emp  = $loan->employee;
            $audience = User::whereHas('role', fn ($q) => $q->where('slug', 'finance_officer'))
                ->where('is_active', true)
                ->get();
            $typeLabel = str_contains((string) $loan->loan_type, 'cash') ? 'Cash Advance' : 'Company Loan';
            $this->notifications->send($audience, 'loans.submitted', [
                'title'       => "{$typeLabel} Request from {$emp->full_name}",
                'message'     => "₱" . number_format((float) $loan->principal, 2) . " — awaiting Finance approval.",
                'link_to'     => "/hr/loans/{$loan->hash_id}",
                'entity_type' => 'employee_loan',
                'entity_id'   => $loan->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLoanSubmitted failed', ['error' => $e->getMessage()]);
        }
    }
}
```

```php
// api/app/Modules/Loans/Listeners/NotifyOnLoanDecided.php
<?php
declare(strict_types=1);
namespace App\Modules\Loans\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Loans\Events\LoanDecided;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnLoanDecided implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(LoanDecided $event): void
    {
        try {
            $loan = $event->loan->loadMissing('employee.user');
            $user = $loan->employee?->user;
            if (! $user) return;
            $type  = $event->approved ? 'loans.approved' : 'loans.rejected';
            $label = $event->approved ? 'Approved' : 'Rejected';
            $typeLabel = str_contains((string) $loan->loan_type, 'cash') ? 'Cash Advance' : 'Loan';
            $this->notifications->send($user, $type, [
                'title'       => "{$typeLabel} Request {$label}",
                'message'     => "Your ₱" . number_format((float) $loan->principal, 2) . " {$typeLabel} request was {$label}.",
                'link_to'     => "/self-service/loans/{$loan->hash_id}",
                'entity_type' => 'employee_loan',
                'entity_id'   => $loan->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLoanDecided failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 3.3: Fire events from LoanService**

In `api/app/Modules/Loans/Services/LoanService.php`, add imports:

```php
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
```

In `create()`, after the loan is persisted and submitted for approval, before the `return`:
```php
event(new LoanSubmitted($loan));
```

In `approve()`, before the `return`:
```php
event(new LoanDecided($loan->fresh(['employee']), true));
```

In `reject()`, before the `return`:
```php
event(new LoanDecided($loan->fresh(['employee']), false));
```

- [ ] **Step 3.4: Register listeners in AppServiceProvider**

Add imports:
```php
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use App\Modules\Loans\Listeners\NotifyOnLoanDecided;
use App\Modules\Loans\Listeners\NotifyOnLoanSubmitted;
```

Inside `boot()`:
```php
Event::listen(LoanSubmitted::class, [NotifyOnLoanSubmitted::class, 'handle']);
Event::listen(LoanDecided::class,   [NotifyOnLoanDecided::class,   'handle']);
```

- [ ] **Step 3.5: Write feature test**

Create `api/tests/Feature/Notifications/LoanNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Notifications;
use App\Modules\Loans\Events\LoanDecided;
use App\Modules\Loans\Events\LoanSubmitted;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LoanNotificationTest extends TestCase
{
    public function test_loan_submitted_fires_event(): void
    {
        Event::fake([LoanSubmitted::class]);
        $loan = \App\Modules\Loans\Models\EmployeeLoan::factory()->pending()->create();
        Event::assertDispatched(LoanSubmitted::class, fn ($e) => $e->loan->is($loan));
    }

    public function test_loan_approved_fires_decided_event(): void
    {
        Event::fake([LoanDecided::class]);
        $loan    = \App\Modules\Loans\Models\EmployeeLoan::factory()->pending()->create();
        $finance = $this->userWithRole('finance_officer');
        app(\App\Modules\Loans\Services\LoanService::class)->approve($loan, $finance);
        Event::assertDispatched(LoanDecided::class, fn ($e) => $e->approved === true);
    }

    public function test_loan_rejected_fires_decided_event(): void
    {
        Event::fake([LoanDecided::class]);
        $loan    = \App\Modules\Loans\Models\EmployeeLoan::factory()->pending()->create();
        $finance = $this->userWithRole('finance_officer');
        app(\App\Modules\Loans\Services\LoanService::class)->reject($loan, $finance, 'Exceeded limit.');
        Event::assertDispatched(LoanDecided::class, fn ($e) => $e->approved === false);
    }

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', $slug)->firstOrFail();
        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 3.6: Run tests**

```bash
php artisan test --filter=LoanNotificationTest
```

Expected: 3 tests pass.

- [ ] **Step 3.7: Commit**

```bash
git add api/app/Modules/Loans/Events/ \
        api/app/Modules/Loans/Listeners/Notify*.php \
        api/app/Modules/Loans/Services/LoanService.php \
        api/app/Providers/AppServiceProvider.php \
        api/tests/Feature/Notifications/LoanNotificationTest.php
git commit -m "feat(notifications): loan request submission and decision notifications"
```

---

### Task 4: Work Order completed notification

`WorkOrderCompleted` event already fires. This task adds a listener that notifies PPC Head and Production Manager.

**Files:**
- Create: `api/app/Modules/Production/Listeners/NotifyOnWorkOrderCompleted.php`
- Modify: `api/app/Providers/AppServiceProvider.php`
- Test: `api/tests/Feature/Notifications/WorkOrderNotificationTest.php`

- [ ] **Step 4.1: Create the listener**

```php
// api/app/Modules/Production/Listeners/NotifyOnWorkOrderCompleted.php
<?php
declare(strict_types=1);
namespace App\Modules\Production\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\WorkOrderCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnWorkOrderCompleted implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(WorkOrderCompleted $event): void
    {
        try {
            $wo  = $event->workOrder->loadMissing('product:id,name');
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['ppc_head', 'production_manager']))
                ->where('is_active', true)
                ->get();
            $this->notifications->send($audience, 'production.wo_completed', [
                'title'       => "Work Order {$wo->wo_number} Completed",
                'message'     => "{$wo->product?->name} — {$wo->quantity_actual} units produced. Ready for outgoing QC.",
                'link_to'     => "/production/work-orders/{$wo->hash_id}",
                'entity_type' => 'work_order',
                'entity_id'   => $wo->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnWorkOrderCompleted failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 4.2: Register listener in AppServiceProvider**

Add import:
```php
use App\Modules\Production\Listeners\NotifyOnWorkOrderCompleted;
```

In `boot()`, add after the existing `WorkOrderCompleted` registration:
```php
Event::listen(WorkOrderCompleted::class, [NotifyOnWorkOrderCompleted::class, 'handle']);
```

> Note: `WorkOrderCompleted` is already imported for `TriggerOutgoingQC`. Add only the new listener line.

- [ ] **Step 4.3: Write feature test**

Create `api/tests/Feature/Notifications/WorkOrderNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Notifications;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Listeners\NotifyOnWorkOrderCompleted;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkOrderNotificationTest extends TestCase
{
    public function test_wo_completed_event_fires_notification_listener(): void
    {
        Event::fake();
        $wo = \App\Modules\Production\Models\WorkOrder::factory()->completed()->create();
        event(new WorkOrderCompleted($wo));
        Event::assertListening(WorkOrderCompleted::class, NotifyOnWorkOrderCompleted::class);
    }

    public function test_listener_sends_to_ppc_and_production_manager(): void
    {
        $ppc  = $this->userWithRole('ppc_head');
        $pm   = $this->userWithRole('production_manager');
        $wo   = \App\Modules\Production\Models\WorkOrder::factory()->completed()->create();
        $listener = app(NotifyOnWorkOrderCompleted::class);
        $listener->handle(new WorkOrderCompleted($wo));
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $ppc->id,
            'type'          => 'production.wo_completed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pm->id,
            'type'          => 'production.wo_completed',
        ]);
    }

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', $slug)->firstOrFail();
        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 4.4: Run tests**

```bash
php artisan test --filter=WorkOrderNotificationTest
```

Expected: 2 tests pass.

- [ ] **Step 4.5: Commit**

```bash
git add api/app/Modules/Production/Listeners/NotifyOnWorkOrderCompleted.php \
        api/app/Providers/AppServiceProvider.php \
        api/tests/Feature/Notifications/WorkOrderNotificationTest.php
git commit -m "feat(notifications): work order completed notification to PPC head and production manager"
```

---

### Task 5: QC inspection failed notification

`InspectionFailed` event already fires. Add a listener that notifies Production Manager and QC Inspector.

**Files:**
- Create: `api/app/Modules/Quality/Listeners/NotifyOnInspectionFailed.php`
- Modify: `api/app/Providers/AppServiceProvider.php`
- Test: `api/tests/Feature/Notifications/InspectionNotificationTest.php`

- [ ] **Step 5.1: Create the listener**

```php
// api/app/Modules/Quality/Listeners/NotifyOnInspectionFailed.php
<?php
declare(strict_types=1);
namespace App\Modules\Quality\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Events\InspectionFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnInspectionFailed implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(InspectionFailed $event): void
    {
        try {
            $inspection = $event->inspection->loadMissing('workOrder:id,wo_number');
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['production_manager', 'qc_inspector']))
                ->where('is_active', true)
                ->get();
            $ref = $inspection->workOrder?->wo_number
                ?? "Inspection #{$inspection->inspection_no}";
            $this->notifications->send($audience, 'quality.inspection_failed', [
                'title'       => "QC Failure — {$ref}",
                'message'     => "Inspection {$inspection->inspection_no} failed. NCR may be required.",
                'link_to'     => "/quality/inspections/{$inspection->hash_id}",
                'entity_type' => 'inspection',
                'entity_id'   => $inspection->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnInspectionFailed failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 5.2: Register listener in AppServiceProvider**

Add import:
```php
use App\Modules\Quality\Listeners\NotifyOnInspectionFailed;
```

In `boot()`:
```php
Event::listen(InspectionFailed::class, [NotifyOnInspectionFailed::class, 'handle']);
```

> Note: `InspectionFailed` is already imported for `RejectGRNOnQcFail`. Add only the new listener line.

- [ ] **Step 5.3: Write feature test**

Create `api/tests/Feature/Notifications/InspectionNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Notifications;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Listeners\NotifyOnInspectionFailed;
use Tests\TestCase;

class InspectionNotificationTest extends TestCase
{
    public function test_listener_sends_to_production_manager_and_qc_inspector(): void
    {
        $pm  = $this->userWithRole('production_manager');
        $qc  = $this->userWithRole('qc_inspector');
        $inspection = \App\Modules\Quality\Models\Inspection::factory()->failed()->create();
        $listener = app(NotifyOnInspectionFailed::class);
        $listener->handle(new InspectionFailed($inspection));
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pm->id,
            'type'          => 'quality.inspection_failed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $qc->id,
            'type'          => 'quality.inspection_failed',
        ]);
    }

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', $slug)->firstOrFail();
        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 5.4: Run tests**

```bash
php artisan test --filter=InspectionNotificationTest
```

Expected: 1 test passes.

- [ ] **Step 5.5: Commit**

```bash
git add api/app/Modules/Quality/Listeners/NotifyOnInspectionFailed.php \
        api/app/Providers/AppServiceProvider.php \
        api/tests/Feature/Notifications/InspectionNotificationTest.php
git commit -m "feat(notifications): QC inspection failure notification to production manager and QC inspectors"
```

---

### Task 6: GRN received notification

`GoodsReceiptNoteCreated` event already fires (triggers incoming QC). Add a listener that notifies the Purchasing Officer so they know their PO has been physically received.

**Files:**
- Create: `api/app/Modules/Inventory/Listeners/NotifyOnGrnReceived.php`
- Modify: `api/app/Providers/AppServiceProvider.php`
- Test: `api/tests/Feature/Notifications/GrnNotificationTest.php`

- [ ] **Step 6.1: Check what GoodsReceiptNoteCreated carries**

```bash
cat api/app/Modules/Inventory/Events/GoodsReceiptNoteCreated.php
```

Note the constructor parameters — the listener needs the GRN and its PO link.

- [ ] **Step 6.2: Create the listener**

```php
// api/app/Modules/Inventory/Listeners/NotifyOnGrnReceived.php
<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnGrnReceived implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(GoodsReceiptNoteCreated $event): void
    {
        try {
            $grn = $event->grn->loadMissing('purchaseOrder:id,po_number');
            $audience = User::whereHas('role', fn ($q) => $q->where('slug', 'purchasing_officer'))
                ->where('is_active', true)
                ->get();
            $ref = $grn->purchaseOrder?->po_number ?? $grn->grn_number;
            $this->notifications->send($audience, 'inventory.grn_received', [
                'title'       => "GRN Received — {$grn->grn_number}",
                'message'     => "Goods received against {$ref}. Incoming QC in progress.",
                'link_to'     => "/inventory/grn/{$grn->hash_id}",
                'entity_type' => 'grn',
                'entity_id'   => $grn->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnGrnReceived failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 6.3: Register listener in AppServiceProvider**

Add import:
```php
use App\Modules\Inventory\Listeners\NotifyOnGrnReceived;
```

In `boot()`:
```php
Event::listen(GoodsReceiptNoteCreated::class, [NotifyOnGrnReceived::class, 'handle']);
```

> Note: `GoodsReceiptNoteCreated` is already imported for `TriggerIncomingQC`. Add only the listener line.

- [ ] **Step 6.4: Write feature test**

Create `api/tests/Feature/Notifications/GrnNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Notifications;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Listeners\NotifyOnGrnReceived;
use Tests\TestCase;

class GrnNotificationTest extends TestCase
{
    public function test_listener_sends_to_purchasing_officer(): void
    {
        $po = $this->userWithRole('purchasing_officer');
        $grn = \App\Modules\Inventory\Models\GoodsReceiptNote::factory()->create();
        $listener = app(NotifyOnGrnReceived::class);
        $listener->handle(new GoodsReceiptNoteCreated($grn));
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $po->id,
            'type'          => 'inventory.grn_received',
        ]);
    }

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', $slug)->firstOrFail();
        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 6.5: Run tests**

```bash
php artisan test --filter=GrnNotificationTest
```

Expected: 1 test passes.

- [ ] **Step 6.6: Commit**

```bash
git add api/app/Modules/Inventory/Listeners/NotifyOnGrnReceived.php \
        api/app/Providers/AppServiceProvider.php \
        api/tests/Feature/Notifications/GrnNotificationTest.php
git commit -m "feat(notifications): GRN received notification to purchasing officer"
```

---

### Task 7: Machine breakdown notification

`MachineBreakdownDetected` event already fires from `HandleMachineBreakdown`. Add a listener that notifies Maintenance Techs and Production Manager.

**Files:**
- Create: `api/app/Modules/Production/Listeners/NotifyOnMachineBreakdown.php`
- Modify: `api/app/Providers/AppServiceProvider.php`
- Test: `api/tests/Feature/Notifications/MachineBreakdownNotificationTest.php`

- [ ] **Step 7.1: Create the listener**

```php
// api/app/Modules/Production/Listeners/NotifyOnMachineBreakdown.php
<?php
declare(strict_types=1);
namespace App\Modules\Production\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Events\MachineBreakdownDetected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnMachineBreakdown implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(MachineBreakdownDetected $event): void
    {
        try {
            $machine = $event->machine;
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['maintenance_tech', 'production_manager']))
                ->where('is_active', true)
                ->get();
            $woInfo = $event->pausedWorkOrder
                ? " WO {$event->pausedWorkOrder->wo_number} was paused."
                : '';
            $reason = $event->reason ? " Reason: {$event->reason}." : '';
            $this->notifications->send($audience, 'maintenance.breakdown', [
                'title'       => "Machine Breakdown — {$machine->machine_code}",
                'message'     => "{$machine->name} is down.{$woInfo}{$reason}",
                'link_to'     => "/maintenance/machines/{$machine->hash_id}",
                'entity_type' => 'machine',
                'entity_id'   => $machine->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnMachineBreakdown failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 7.2: Register listener in AppServiceProvider**

Add import:
```php
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Listeners\NotifyOnMachineBreakdown;
```

In `boot()`:
```php
Event::listen(MachineBreakdownDetected::class, [NotifyOnMachineBreakdown::class, 'handle']);
```

- [ ] **Step 7.3: Write feature test**

Create `api/tests/Feature/Notifications/MachineBreakdownNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Notifications;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Listeners\NotifyOnMachineBreakdown;
use Tests\TestCase;

class MachineBreakdownNotificationTest extends TestCase
{
    public function test_listener_sends_to_maintenance_tech_and_production_manager(): void
    {
        $tech = $this->userWithRole('maintenance_tech');
        $pm   = $this->userWithRole('production_manager');
        $machine = \App\Modules\MRP\Models\Machine::factory()->create();
        $listener = app(NotifyOnMachineBreakdown::class);
        $listener->handle(new MachineBreakdownDetected($machine, null, [], 'Hydraulic failure'));
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $tech->id,
            'type'          => 'maintenance.breakdown',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $pm->id,
            'type'          => 'maintenance.breakdown',
        ]);
    }

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', $slug)->firstOrFail();
        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 7.4: Run tests**

```bash
php artisan test --filter=MachineBreakdownNotificationTest
```

Expected: 1 test passes.

- [ ] **Step 7.5: Commit**

```bash
git add api/app/Modules/Production/Listeners/NotifyOnMachineBreakdown.php \
        api/app/Providers/AppServiceProvider.php \
        api/tests/Feature/Notifications/MachineBreakdownNotificationTest.php
git commit -m "feat(notifications): machine breakdown notification to maintenance techs and production manager"
```

---

### Task 8: Low stock auto-PR notification

`AutoReplenishmentService::checkAndReplenish()` already creates a PR automatically when stock falls below reorder point. Add a notification to the Purchasing Officer when this happens so they know to process the auto-generated PR promptly.

**Files:**
- Create: `api/app/Modules/Inventory/Listeners/NotifyOnLowStockPrCreated.php`
- Modify: `api/app/Modules/Inventory/Services/AutoReplenishmentService.php`
- Modify: `api/app/Providers/AppServiceProvider.php`
- Test: `api/tests/Feature/Notifications/LowStockNotificationTest.php`

- [ ] **Step 8.1: Read AutoReplenishmentService to understand the return value**

```bash
cat api/app/Modules/Inventory/Services/AutoReplenishmentService.php
```

Confirm `checkAndReplenish()` returns `?PurchaseRequest`. Note the `PurchaseRequest` model fields (pr_number, hash_id).

- [ ] **Step 8.2: Create Low Stock event**

```php
// api/app/Modules/Inventory/Events/LowStockPrCreated.php
<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Events;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class LowStockPrCreated
{
    use Dispatchable, SerializesModels;
    public function __construct(
        public Item $item,
        public PurchaseRequest $purchaseRequest,
    ) {}
}
```

- [ ] **Step 8.3: Fire event from AutoReplenishmentService**

In `api/app/Modules/Inventory/Services/AutoReplenishmentService.php`, add import:

```php
use App\Modules\Inventory\Events\LowStockPrCreated;
```

After the PR is created and committed (find the point where `$pr` is returned), fire the event:

```php
// After PR is created, before returning:
event(new LowStockPrCreated($item, $pr));
return $pr;
```

- [ ] **Step 8.4: Create the listener**

```php
// api/app/Modules/Inventory/Listeners/NotifyOnLowStockPrCreated.php
<?php
declare(strict_types=1);
namespace App\Modules\Inventory\Listeners;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\LowStockPrCreated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
class NotifyOnLowStockPrCreated implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}
    public function handle(LowStockPrCreated $event): void
    {
        try {
            $item = $event->item;
            $pr   = $event->purchaseRequest;
            $audience = User::whereHas('role', fn ($q) => $q->whereIn('slug', ['purchasing_officer', 'warehouse_staff']))
                ->where('is_active', true)
                ->get();
            $this->notifications->send($audience, 'inventory.low_stock', [
                'title'       => "Low Stock — {$item->code}",
                'message'     => "{$item->name} below reorder point. Auto-PR {$pr->pr_number} created.",
                'link_to'     => "/purchasing/purchase-requests/{$pr->hash_id}",
                'entity_type' => 'purchase_request',
                'entity_id'   => $pr->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnLowStockPrCreated failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 8.5: Register listener in AppServiceProvider**

Add imports:
```php
use App\Modules\Inventory\Events\LowStockPrCreated;
use App\Modules\Inventory\Listeners\NotifyOnLowStockPrCreated;
```

In `boot()`:
```php
Event::listen(LowStockPrCreated::class, [NotifyOnLowStockPrCreated::class, 'handle']);
```

- [ ] **Step 8.6: Write feature test**

Create `api/tests/Feature/Notifications/LowStockNotificationTest.php`:

```php
<?php
declare(strict_types=1);
namespace Tests\Feature\Notifications;
use App\Modules\Inventory\Events\LowStockPrCreated;
use App\Modules\Inventory\Listeners\NotifyOnLowStockPrCreated;
use Tests\TestCase;

class LowStockNotificationTest extends TestCase
{
    public function test_listener_sends_to_purchasing_officer_and_warehouse(): void
    {
        $po        = $this->userWithRole('purchasing_officer');
        $warehouse = $this->userWithRole('warehouse_staff');
        $item = \App\Modules\Inventory\Models\Item::factory()->create([
            'reorder_point' => 50,
        ]);
        $pr = \App\Modules\Purchasing\Models\PurchaseRequest::factory()->create();
        $listener = app(NotifyOnLowStockPrCreated::class);
        $listener->handle(new LowStockPrCreated($item, $pr));
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $po->id,
            'type'          => 'inventory.low_stock',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $warehouse->id,
            'type'          => 'inventory.low_stock',
        ]);
    }

    private function userWithRole(string $slug): \App\Modules\Auth\Models\User
    {
        $role = \App\Modules\Auth\Models\Role::where('slug', $slug)->firstOrFail();
        return \App\Modules\Auth\Models\User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }
}
```

- [ ] **Step 8.7: Run tests**

```bash
php artisan test --filter=LowStockNotificationTest
```

Expected: 1 test passes.

- [ ] **Step 8.8: Commit**

```bash
git add api/app/Modules/Inventory/Events/LowStockPrCreated.php \
        api/app/Modules/Inventory/Services/AutoReplenishmentService.php \
        api/app/Modules/Inventory/Listeners/NotifyOnLowStockPrCreated.php \
        api/app/Providers/AppServiceProvider.php \
        api/tests/Feature/Notifications/LowStockNotificationTest.php
git commit -m "feat(notifications): low stock auto-PR notification to purchasing officer and warehouse"
```

---

### Task 9: Update frontend notification preferences

Add all new notification types to the `NOTIFICATION_TYPES` constant in the preferences page so users can control which ones they receive.

**Files:**
- Modify: `spa/src/pages/self-service/notification-preferences.tsx`

- [ ] **Step 9.1: Update NOTIFICATION_TYPES constant**

Replace the existing `NOTIFICATION_TYPES` array in `spa/src/pages/self-service/notification-preferences.tsx`:

```typescript
const NOTIFICATION_TYPES: Array<{ key: string; label: string; description: string }> = [
  // — Chain 1: Order to Cash —
  { key: 'chain.so_confirmed',          label: 'Sales order confirmed',       description: 'A sales order has been confirmed by the customer.' },
  { key: 'production.wo_completed',     label: 'Work order completed',        description: 'A production work order has finished. Outgoing QC is next.' },
  { key: 'quality.inspection_failed',   label: 'QC inspection failed',        description: 'A quality inspection failed. An NCR may be required.' },
  { key: 'chain.delivery_confirmed',    label: 'Delivery confirmed',          description: 'A delivery has been confirmed and an invoice draft was created.' },

  // — Chain 2: Procure to Pay —
  { key: 'inventory.grn_received',      label: 'Goods receipt created',       description: 'Goods have been received against a purchase order.' },
  { key: 'inventory.low_stock',         label: 'Low stock alert',             description: 'An item fell below reorder point and an auto-PR was created.' },
  { key: 'chain.pr_approved',           label: 'Purchase request approved',   description: 'A purchase request has been fully approved.' },
  { key: 'chain.po_approved',           label: 'Purchase order approved',     description: 'A purchase order has been fully approved and is ready to send.' },

  // — Chain 3: Hire to Retire —
  { key: 'leave.submitted',             label: 'Leave request submitted',     description: 'An employee has submitted a leave request for your approval.' },
  { key: 'leave.pending_hr',            label: 'Leave pending HR approval',   description: 'A leave request has been approved by the dept head and needs HR sign-off.' },
  { key: 'leave.approved',              label: 'Leave request approved',      description: 'Your leave request has been approved.' },
  { key: 'leave.rejected',              label: 'Leave request rejected',      description: 'Your leave request was not approved.' },
  { key: 'attendance.ot_submitted',     label: 'Overtime request submitted',  description: 'An employee has submitted an overtime request for your approval.' },
  { key: 'attendance.ot_approved',      label: 'Overtime request approved',   description: 'Your overtime request has been approved.' },
  { key: 'attendance.ot_rejected',      label: 'Overtime request rejected',   description: 'Your overtime request was not approved.' },
  { key: 'loans.submitted',             label: 'Loan/CA request submitted',   description: 'An employee has submitted a loan or cash advance for Finance approval.' },
  { key: 'loans.approved',              label: 'Loan/CA approved',            description: 'Your loan or cash advance request has been approved.' },
  { key: 'loans.rejected',              label: 'Loan/CA rejected',            description: 'Your loan or cash advance request was not approved.' },
  { key: 'chain.payslip_ready',         label: 'Payslip ready',               description: 'Your payslip is ready to view.' },
  { key: 'chain.separation_initiated',  label: 'Separation initiated',        description: 'An employee separation process has started.' },

  // — Maintenance —
  { key: 'maintenance.breakdown',       label: 'Machine breakdown',           description: 'A machine has entered breakdown status and may have paused a work order.' },

  // — Approvals —
  { key: 'approval_reminder',           label: 'Approval reminder',           description: 'You have a pending approval that has been waiting over 24 hours.' },
  { key: 'approval_escalation',         label: 'Approval escalation',         description: 'An approval you are responsible for has been escalated due to timeout.' },
];
```

- [ ] **Step 9.2: Verify TypeScript compiles**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 9.3: Commit**

```bash
git add spa/src/pages/self-service/notification-preferences.tsx
git commit -m "feat(notifications): expand preferences matrix with all new notification types"
```

---

## Self-Review

### Spec coverage check

| Requirement | Task |
|---|---|
| Leave submitted → dept head | Task 1 |
| Leave dept approved → HR officer | Task 1 |
| Leave approved → employee | Task 1 |
| Leave rejected → employee | Task 1 |
| OT submitted → dept head | Task 2 |
| OT approved → employee | Task 2 |
| OT rejected → employee | Task 2 |
| Loan submitted → finance officer | Task 3 |
| Loan approved → employee | Task 3 |
| Loan rejected → employee | Task 3 |
| WO completed → PPC + Prod Manager | Task 4 |
| QC failed → Prod Manager + QC | Task 5 |
| GRN received → Purchasing | Task 6 |
| Machine breakdown → Maintenance + Prod Manager | Task 7 |
| Low stock PR → Purchasing + Warehouse | Task 8 |
| Preferences UI covers all types | Task 9 |

### Placeholder scan

No TBD, no "similar to task N", all code complete.

### Type consistency

- All events use `Dispatchable + SerializesModels` — consistent.
- All listeners implement `ShouldQueue` — consistent with existing pattern.
- Notification type strings use `module.event_name` format — consistent with `chain.so_confirmed`.
- `entity_type` / `entity_id` pattern matches existing listeners.
- Link format (`/module/resource/{hash_id}`) mirrors existing entries in `ApprovalEscalationService::linkFor()`.
- `employee.user` eager load path for getting the User from an Employee is consistent across Tasks 1, 2, 3.
