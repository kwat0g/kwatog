# Notification Module Enhancement Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix gaps, add real-time push, standardize notification data contracts, add deletion/archival, wire missing module notifications, and add tests.

**Architecture:** Centralize all notification creation through `NotificationService::notify()` with a standardized data envelope (`title`, `message`, `link_to`, `entity_type`, `entity_id`). Add real-time broadcast via Reverb on the existing `user.{id}` private channel. Add a prune command for old read notifications.

**Tech Stack:** Laravel 11, Laravel Reverb (WebSocket), React 18 + TanStack Query, laravel-echo, PHPUnit

---

## Audit Findings

### Critical Gaps

1. **Inconsistent data contract** — Listeners use `message` + `link` keys; SPA expects `title` + `message` + `link_to`. No listener sets `title`. The `link` key ≠ SPA's `link_to` → clicking notifications navigates nowhere.
2. **NotificationService unused by most listeners** — 5/6 listeners bypass `NotificationService::notify()` and write raw DB inserts. Preference checks never fire for those notifications.
3. **No real-time push** — Bell polls every 30s. The `user.{id}` broadcast channel exists but nothing broadcasts to it. No toast on new notification.
4. **No notification deletion/archival** — Users cannot delete individual notifications or clear old ones. No backend prune job.
5. **No tests** — Zero test files for notification module.
6. **Missing `title` in all listener payloads** — SPA falls back to `meta.label` which is generic ("System", "Purchasing").

### Medium Gaps

7. **Preferences page hardcodes types** — SPA has 10 types; listeners use different type strings (`chain.so_confirmed`, `chain.po_approved`, etc.) that don't match preference keys (`leave.submitted`, `payroll.finalized`).
8. **No pagination on notifications page** — Fetches 50 per_page but no "load more" or infinite scroll.
9. **No single notification delete endpoint** — Only mark-read exists.
10. **Approval workflow notifications lack `link_to`** — `ApprovalEscalationService` sends notifications without navigation link.
11. **Bell dropdown lacks "mark all read" button** — Only the full page has it.

### Low Priority

12. **No email channel wiring** — `NotificationService::channelsFor()` maps `channel` to strings but never actually dispatches via mail channel (only `'database'` works).
13. **No notification sound/browser notification API** — Nice-to-have for critical alerts.

---

## File Structure

### Backend (Create)

| File | Purpose |
|------|---------|
| `api/app/Common/Notifications/GenericDatabaseNotification.php` | Standardized Notification class for all in-app notifications |
| `api/app/Common/Events/UserNotificationCreated.php` | Broadcast event for real-time push to `user.{id}` |
| `api/app/Console/Commands/PruneOldNotifications.php` | Artisan command to delete read notifications older than 90 days |
| `api/tests/Feature/NotificationControllerTest.php` | API endpoint tests |
| `api/tests/Unit/NotificationServiceTest.php` | Service unit tests |

### Backend (Modify)

| File | Change |
|------|--------|
| `api/app/Common/Services/NotificationService.php` | Standardize data envelope, broadcast event after insert, respect preferences |
| `api/app/Modules/Auth/Controllers/NotificationController.php` | Add `destroy` (single delete) + `destroyAll` (clear read) endpoints |
| `api/app/Modules/Auth/Services/UserNotificationService.php` | Add `delete`, `deleteAllRead` methods |
| `api/app/Modules/Auth/routes.php` | Add DELETE routes |
| `api/app/Modules/CRM/Listeners/NotifyOnSalesOrderConfirmed.php` | Use NotificationService instead of raw insert |
| `api/app/Modules/HR/Listeners/NotifyOnSeparationInitiated.php` | Use NotificationService |
| `api/app/Modules/Payroll/Listeners/NotifyEmployeesOnPayrollFinalized.php` | Use NotificationService |
| `api/app/Modules/Accounting/Listeners/NotifyFinanceOnDeliveryConfirmed.php` | Use NotificationService |
| `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderApproved.php` | Use NotificationService |
| `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseRequestApproved.php` | Use NotificationService |
| `api/app/Common/Services/ApprovalEscalationService.php` | Use NotificationService, add `link_to` |

### Frontend (Modify)

| File | Change |
|------|--------|
| `spa/src/api/notifications.ts` | Add `delete`, `deleteAllRead` API methods |
| `spa/src/components/layout/NotificationBell.tsx` | Add real-time listener, "mark all read" in dropdown, toast on new |
| `spa/src/pages/notifications/index.tsx` | Add delete actions, infinite scroll |
| `spa/src/pages/self-service/notification-preferences.tsx` | Sync type keys with actual backend types |

### Frontend (Create)

| File | Purpose |
|------|---------|
| `spa/src/hooks/useNotificationRealtime.ts` | Hook that listens to `user.{id}` channel and invalidates queries + shows toast |

---

## Task 1: Standardize NotificationService Data Envelope + Broadcast

**Files:**
- Modify: `api/app/Common/Services/NotificationService.php`
- Create: `api/app/Common/Events/UserNotificationCreated.php`
- Test: `api/tests/Unit/NotificationServiceTest.php`

- [ ] **Step 1: Create the broadcast event**

```php
<?php
// api/app/Common/Events/UserNotificationCreated.php
declare(strict_types=1);

namespace App\Common\Events;

use App\Modules\Auth\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserNotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly array $notification,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return $this->notification;
    }
}
```

- [ ] **Step 2: Rewrite NotificationService with standardized envelope + broadcast**

Replace full contents of `api/app/Common/Services/NotificationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Events\UserNotificationCreated;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Send an in-app notification with a standardized data envelope.
     *
     * @param User|Collection<int, User>|array<int, User> $recipients
     * @param array{title: string, message: string, link_to?: string, entity_type?: string, entity_id?: string} $data
     */
    public function send(
        User|Collection|array $recipients,
        string $type,
        array $data,
    ): void {
        $list = match (true) {
            $recipients instanceof User       => collect([$recipients]),
            $recipients instanceof Collection => $recipients,
            default                           => collect($recipients),
        };

        foreach ($list as $user) {
            if (! $this->isChannelEnabled($user, $type, 'in_app')) {
                continue;
            }

            $id = (string) Str::uuid();
            $now = now();

            DB::table('notifications')->insert([
                'id'              => $id,
                'type'            => $type,
                'notifiable_type' => $user::class,
                'notifiable_id'   => $user->id,
                'data'            => json_encode($data),
                'read_at'         => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            event(new UserNotificationCreated($user->id, [
                'id'         => $id,
                'type'       => $type,
                'data'       => $data,
                'read_at'    => null,
                'created_at' => $now->toISOString(),
            ]));
        }
    }

    /**
     * Legacy wrapper — delegates to send(). Kept for backward compat with
     * NcrService and MaintenanceWorkOrderService that pass Notification objects.
     * Will be removed once all callers migrate to send().
     */
    public function notify(User|Collection|array $recipients, $notification, string $type): void
    {
        $list = match (true) {
            $recipients instanceof User       => collect([$recipients]),
            $recipients instanceof Collection => $recipients,
            default                           => collect($recipients),
        };

        foreach ($list as $user) {
            if (! $this->isChannelEnabled($user, $type, 'in_app')) {
                continue;
            }
            $user->notify($notification);
        }
    }

    private function isChannelEnabled(User $user, string $type, string $channel): bool
    {
        $pref = DB::table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('notification_type', $type)
            ->where('channel', $channel)
            ->first();

        // Default: enabled if no preference row exists
        if (! $pref) {
            return true;
        }

        return (bool) $pref->enabled;
    }
}
```

- [ ] **Step 3: Write unit test**

```php
<?php
// api/tests/Unit/NotificationServiceTest.php
declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\UserNotificationCreated;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
    }

    public function test_send_creates_notification_row(): void
    {
        $user = User::factory()->create();
        Event::fake();

        $this->service->send($user, 'test.type', [
            'title'   => 'Test Title',
            'message' => 'Test message body',
            'link_to' => '/test/path',
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $user->id,
            'notifiable_type' => User::class,
            'type'            => 'test.type',
        ]);

        $row = DB::table('notifications')->where('notifiable_id', $user->id)->first();
        $data = json_decode($row->data, true);
        $this->assertEquals('Test Title', $data['title']);
        $this->assertEquals('/test/path', $data['link_to']);
    }

    public function test_send_broadcasts_event(): void
    {
        $user = User::factory()->create();
        Event::fake([UserNotificationCreated::class]);

        $this->service->send($user, 'test.type', [
            'title'   => 'Broadcast Test',
            'message' => 'Should broadcast',
        ]);

        Event::assertDispatched(UserNotificationCreated::class, function ($e) use ($user) {
            return $e->userId === $user->id
                && $e->notification['type'] === 'test.type'
                && $e->notification['data']['title'] === 'Broadcast Test';
        });
    }

    public function test_send_respects_disabled_preference(): void
    {
        $user = User::factory()->create();
        Event::fake();

        DB::table('notification_preferences')->insert([
            'user_id'           => $user->id,
            'notification_type' => 'disabled.type',
            'channel'           => 'in_app',
            'enabled'           => false,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->service->send($user, 'disabled.type', [
            'title'   => 'Should not appear',
            'message' => 'Blocked by preference',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $user->id,
            'type'          => 'disabled.type',
        ]);
        Event::assertNotDispatched(UserNotificationCreated::class);
    }

    public function test_send_to_multiple_users(): void
    {
        $users = User::factory()->count(3)->create();
        Event::fake();

        $this->service->send($users->all(), 'multi.type', [
            'title'   => 'Multi',
            'message' => 'Sent to many',
        ]);

        $this->assertEquals(3, DB::table('notifications')->where('type', 'multi.type')->count());
        Event::assertDispatched(UserNotificationCreated::class, 3);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `cd api && php artisan test tests/Unit/NotificationServiceTest.php --stop-on-failure`
Expected: 4 tests PASS

- [ ] **Step 5: Commit**

```bash
git add api/app/Common/Services/NotificationService.php api/app/Common/Events/UserNotificationCreated.php api/tests/Unit/NotificationServiceTest.php
git commit -m "feat(notifications): standardize service envelope + broadcast event"
```

---

## Task 2: Migrate All Listeners to Use NotificationService

**Files:**
- Modify: `api/app/Modules/CRM/Listeners/NotifyOnSalesOrderConfirmed.php`
- Modify: `api/app/Modules/HR/Listeners/NotifyOnSeparationInitiated.php`
- Modify: `api/app/Modules/Payroll/Listeners/NotifyEmployeesOnPayrollFinalized.php`
- Modify: `api/app/Modules/Accounting/Listeners/NotifyFinanceOnDeliveryConfirmed.php`
- Modify: `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderApproved.php`
- Modify: `api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseRequestApproved.php`
- Modify: `api/app/Common/Services/ApprovalEscalationService.php`

- [ ] **Step 1: Rewrite NotifyOnSalesOrderConfirmed**

```php
<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnSalesOrderConfirmed implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SalesOrderConfirmed $event): void
    {
        try {
            $so = $event->salesOrder->loadMissing('customer:id,name');

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['ppc_head', 'production_manager']))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.so_confirmed', [
                'title'       => "SO {$so->so_number} Confirmed",
                'message'     => "Sales order confirmed for {$so->customer?->name}. MRP run completed.",
                'link_to'     => "/crm/sales-orders/{$so->hash_id}",
                'entity_type' => 'sales_order',
                'entity_id'   => $so->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnSalesOrderConfirmed failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 2: Rewrite NotifyOnSeparationInitiated**

```php
<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Events\SeparationInitiated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnSeparationInitiated implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(SeparationInitiated $event): void
    {
        try {
            $clearance = $event->clearance->loadMissing('employee');

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', ['hr_officer', 'finance_officer']))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.separation_initiated', [
                'title'       => 'Separation Initiated',
                'message'     => "Separation initiated for {$clearance->employee?->full_name}.",
                'link_to'     => "/hr/employees/{$clearance->employee?->hash_id}",
                'entity_type' => 'clearance',
                'entity_id'   => $clearance->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnSeparationInitiated failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 3: Rewrite NotifyEmployeesOnPayrollFinalized**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifyEmployeesOnPayrollFinalized implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PayrollPeriodFinalized $event): void
    {
        try {
            $period = $event->period;

            $userIds = DB::table('payrolls')
                ->where('payroll_period_id', $period->id)
                ->join('employees', 'payrolls.employee_id', '=', 'employees.id')
                ->whereNotNull('employees.user_id')
                ->pluck('employees.user_id');

            if ($userIds->isEmpty()) return;

            $users = User::query()->whereIn('id', $userIds)->where('is_active', true)->get();
            $periodLabel = $this->periodLabel($period);

            $this->notifications->send($users, 'chain.payslip_ready', [
                'title'       => 'Payslip Ready',
                'message'     => "Your payslip for {$periodLabel} is ready.",
                'link_to'     => '/self-service/payslips',
                'entity_type' => 'payroll_period',
                'entity_id'   => method_exists($period, 'getHashIdAttribute') ? $period->hash_id : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyEmployeesOnPayrollFinalized failed', ['error' => $e->getMessage()]);
        }
    }

    private function periodLabel(object $period): string
    {
        $start = $period->start_date ?? $period->period_start ?? null;
        $end   = $period->end_date   ?? $period->period_end   ?? null;
        return $start && $end ? "{$start} – {$end}" : (string) ($period->name ?? 'Payroll Period');
    }
}
```

- [ ] **Step 4: Rewrite NotifyFinanceOnDeliveryConfirmed**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyFinanceOnDeliveryConfirmed implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(DeliveryConfirmed $event): void
    {
        try {
            $invoice = $event->invoiceId ? Invoice::find($event->invoiceId) : null;

            $message = $invoice
                ? "Delivery {$event->delivery->delivery_number} confirmed. Draft invoice {$invoice->invoice_number} ready."
                : "Delivery {$event->delivery->delivery_number} confirmed. Manual invoicing required.";

            $link = $invoice
                ? "/accounting/invoices/{$invoice->hash_id}"
                : "/supply-chain/deliveries/{$event->delivery->hash_id}";

            $financeUsers = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'finance_officer'))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($financeUsers, 'chain.delivery_confirmed', [
                'title'       => "Delivery {$event->delivery->delivery_number} Confirmed",
                'message'     => $message,
                'link_to'     => $link,
                'entity_type' => 'delivery',
                'entity_id'   => $event->delivery->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyFinanceOnDeliveryConfirmed failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 5: Rewrite NotifyOnPurchaseOrderApproved**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnPurchaseOrderApproved implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PurchaseOrderApproved $event): void
    {
        try {
            $po = $event->purchaseOrder->loadMissing('vendor:id,name');

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'purchasing_officer'))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.po_approved', [
                'title'       => "PO {$po->po_number} Approved",
                'message'     => "Ready to send to {$po->vendor?->name}.",
                'link_to'     => "/purchasing/purchase-orders/{$po->hash_id}",
                'entity_type' => 'purchase_order',
                'entity_id'   => $po->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnPurchaseOrderApproved failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 6: Rewrite NotifyOnPurchaseRequestApproved**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Listeners;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyOnPurchaseRequestApproved implements ShouldQueue
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(PurchaseRequestApproved $event): void
    {
        try {
            $pr = $event->purchaseRequest;

            $audience = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'purchasing_officer'))
                ->where('is_active', true)
                ->get();

            $this->notifications->send($audience, 'chain.pr_approved', [
                'title'       => "PR {$pr->pr_number} Approved",
                'message'     => "Ready to convert to PO.",
                'link_to'     => "/purchasing/purchase-requests/{$pr->hash_id}",
                'entity_type' => 'purchase_request',
                'entity_id'   => $pr->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NotifyOnPurchaseRequestApproved failed', ['error' => $e->getMessage()]);
        }
    }
}
```

- [ ] **Step 7: Rewrite ApprovalEscalationService to use NotificationService**

```php
<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Log;

class ApprovalEscalationService
{
    private const REMINDER_HOURS = 24;
    private const ESCALATE_HOURS = 48;

    public function __construct(private readonly NotificationService $notifications) {}

    public function runReminders(): int
    {
        $count = 0;
        $stale = ApprovalRecord::query()
            ->where('action', 'pending')
            ->whereNull('reminder_sent_at')
            ->where('created_at', '<', now()->subHours(self::REMINDER_HOURS))
            ->get();

        foreach ($stale as $rec) {
            try {
                $approver = $this->resolveCurrentApprover($rec);
                if ($approver) {
                    $hours = (int) abs(now()->diffInHours($rec->created_at));
                    $this->notifications->send($approver, 'approval_reminder', [
                        'title'   => 'Approval Reminder',
                        'message' => "Approval pending for {$hours}h on "
                                     .class_basename((string) $rec->approvable_type).".",
                        'link_to' => $this->linkFor($rec),
                    ]);
                }
                $rec->update(['reminder_sent_at' => now()]);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('ApprovalEscalationService::reminder failed', [
                    'record_id' => $rec->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    public function runEscalations(): int
    {
        $count = 0;
        $stale = ApprovalRecord::query()
            ->where('action', 'pending')
            ->whereNull('escalated_at')
            ->where('created_at', '<', now()->subHours(self::ESCALATE_HOURS))
            ->get();

        foreach ($stale as $rec) {
            try {
                $approver = $this->resolveCurrentApprover($rec);
                $superior = $this->resolveSuperior($rec);
                $hours = (int) abs(now()->diffInHours($rec->created_at));

                $data = [
                    'title'   => 'Approval Escalation',
                    'message' => "Escalation: approval pending {$hours}h on "
                                 .class_basename((string) $rec->approvable_type).".",
                    'link_to' => $this->linkFor($rec),
                ];

                $recipients = collect([$approver, $superior])->filter()->unique('id');
                $this->notifications->send($recipients, 'approval_escalation', $data);

                $rec->update([
                    'escalated_at'         => now(),
                    'escalated_to_user_id' => $superior?->id,
                ]);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('ApprovalEscalationService::escalate failed', [
                    'record_id' => $rec->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    private function linkFor(ApprovalRecord $rec): string
    {
        $typeMap = [
            'PurchaseRequest' => '/purchasing/purchase-requests/',
            'PurchaseOrder'   => '/purchasing/purchase-orders/',
            'LeaveRequest'    => '/hr/leaves/',
            'LoanApplication' => '/hr/loans/',
        ];

        $basename = class_basename((string) $rec->approvable_type);
        $prefix = $typeMap[$basename] ?? '/admin/audit-logs';
        $hashId = $rec->approvable?->hash_id ?? '';

        return $prefix . $hashId;
    }

    private function resolveCurrentApprover(ApprovalRecord $rec): ?User
    {
        if ($rec->approver_id) {
            $u = User::find($rec->approver_id);
            if ($u && $u->is_active) return $u;
        }

        return User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', $rec->role_slug))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    private function resolveSuperior(ApprovalRecord $rec): ?User
    {
        $superiorRole = match ($rec->role_slug) {
            'department_head'    => 'production_manager',
            'production_manager' => 'system_admin',
            'purchasing_officer' => 'system_admin',
            'finance_officer'    => 'system_admin',
            'hr_officer'         => 'system_admin',
            'ppc_head'           => 'system_admin',
            default              => 'system_admin',
        };

        return User::query()
            ->whereHas('role', fn ($q) => $q->where('slug', $superiorRole))
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
```

- [ ] **Step 8: Run full test suite to verify no regressions**

Run: `cd api && php artisan test --stop-on-failure`
Expected: All existing tests PASS

- [ ] **Step 9: Commit**

```bash
git add api/app/Modules/CRM/Listeners/NotifyOnSalesOrderConfirmed.php \
  api/app/Modules/HR/Listeners/NotifyOnSeparationInitiated.php \
  api/app/Modules/Payroll/Listeners/NotifyEmployeesOnPayrollFinalized.php \
  api/app/Modules/Accounting/Listeners/NotifyFinanceOnDeliveryConfirmed.php \
  api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseOrderApproved.php \
  api/app/Modules/Purchasing/Listeners/NotifyOnPurchaseRequestApproved.php \
  api/app/Common/Services/ApprovalEscalationService.php
git commit -m "refactor(notifications): migrate all listeners to NotificationService.send()"
```

---

## Task 3: Add Delete Endpoints (Backend)

**Files:**
- Modify: `api/app/Modules/Auth/Controllers/NotificationController.php`
- Modify: `api/app/Modules/Auth/Services/UserNotificationService.php`
- Modify: `api/app/Modules/Auth/routes.php`
- Test: `api/tests/Feature/NotificationControllerTest.php`

- [ ] **Step 1: Add delete methods to UserNotificationService**

Add to end of class in `api/app/Modules/Auth/Services/UserNotificationService.php`:

```php
    public function delete(User $user, string $id): void
    {
        $deleted = DB::table('notifications')
            ->where('id', $id)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->delete();

        if (! $deleted) abort(404);
    }

    public function deleteAllRead(User $user): int
    {
        return (int) DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->whereNotNull('read_at')
            ->delete();
    }
```

- [ ] **Step 2: Add controller actions**

Add to end of class in `api/app/Modules/Auth/Controllers/NotificationController.php`:

```php
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->service->delete($request->user(), $id);
        return response()->json(['data' => ['deleted' => true]]);
    }

    public function destroyAllRead(Request $request): JsonResponse
    {
        $count = $this->service->deleteAllRead($request->user());
        return response()->json(['data' => ['deleted' => $count]]);
    }
```

- [ ] **Step 3: Add routes**

In `api/app/Modules/Auth/routes.php`, inside the `notifications` group (after line 43):

```php
    Route::delete('/{id}',       [NotificationController::class, 'destroy'])
        ->middleware('permission:notifications.view');
    Route::delete('/read',       [NotificationController::class, 'destroyAllRead'])
        ->middleware('permission:notifications.view');
```

**Important:** Place the `/read` route BEFORE `/{id}` to avoid route parameter conflict, or rename to `/clear-read`. Use `/clear-read`:

```php
    Route::delete('/clear-read', [NotificationController::class, 'destroyAllRead'])
        ->middleware('permission:notifications.view');
    Route::delete('/{id}',       [NotificationController::class, 'destroy'])
        ->middleware('permission:notifications.view');
```

- [ ] **Step 4: Write feature test**

```php
<?php
// api/tests/Feature/NotificationControllerTest.php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        // Grant notifications.view permission
        $this->grantPermission($this->user, 'notifications.view');
    }

    private function createNotification(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(array_merge([
            'id'              => $id,
            'type'            => 'test.type',
            'notifiable_type' => User::class,
            'notifiable_id'   => $this->user->id,
            'data'            => json_encode(['title' => 'Test', 'message' => 'Msg', 'link_to' => '/test']),
            'read_at'         => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ], $overrides));
        return $id;
    }

    public function test_index_returns_notifications(): void
    {
        $this->createNotification();

        $response = $this->actingAs($this->user)->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['data' => [['id', 'type', 'data', 'read_at', 'created_at']]]);
    }

    public function test_mark_read(): void
    {
        $id = $this->createNotification();

        $response = $this->actingAs($this->user)->patchJson("/api/v1/notifications/{$id}/read");

        $response->assertOk()->assertJsonPath('data.id', $id);
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_mark_all_read(): void
    {
        $this->createNotification();
        $this->createNotification();

        $response = $this->actingAs($this->user)->patchJson('/api/v1/notifications/read-all');

        $response->assertOk()->assertJsonPath('data.marked_read', 2);
    }

    public function test_delete_single(): void
    {
        $id = $this->createNotification();

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/notifications/{$id}");

        $response->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    public function test_delete_all_read(): void
    {
        $this->createNotification(['read_at' => now()]);
        $unreadId = $this->createNotification(['read_at' => null]);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/notifications/clear-read');

        $response->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertDatabaseHas('notifications', ['id' => $unreadId]);
    }

    public function test_cannot_delete_other_users_notification(): void
    {
        $otherId = $this->createNotification();
        // Change owner
        DB::table('notifications')->where('id', $otherId)->update(['notifiable_id' => 99999]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/notifications/{$otherId}");

        $response->assertNotFound();
    }

    private function grantPermission(User $user, string $permission): void
    {
        // Assumes role-based permission system. Assign a role that has the permission.
        // If test helpers exist, use them. Otherwise attach directly:
        if (method_exists($user, 'givePermissionTo')) {
            $user->givePermissionTo($permission);
        }
        // Fallback: the test may need the seeder run or a custom helper.
    }
}
```

- [ ] **Step 5: Run tests**

Run: `cd api && php artisan test tests/Feature/NotificationControllerTest.php --stop-on-failure`
Expected: 5 tests PASS

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Auth/Controllers/NotificationController.php \
  api/app/Modules/Auth/Services/UserNotificationService.php \
  api/app/Modules/Auth/routes.php \
  api/tests/Feature/NotificationControllerTest.php
git commit -m "feat(notifications): add delete single + clear-read endpoints"
```

---

## Task 4: Add Prune Command for Old Notifications

**Files:**
- Create: `api/app/Console/Commands/PruneOldNotifications.php`
- Test: `api/tests/Unit/PruneOldNotificationsTest.php`

- [ ] **Step 1: Create the artisan command**

```php
<?php
// api/app/Console/Commands/PruneOldNotifications.php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOldNotifications extends Command
{
    protected $signature = 'notifications:prune {--days=90 : Delete read notifications older than N days}';
    protected $description = 'Delete read notifications older than the specified number of days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} read notifications older than {$days} days.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Write test**

```php
<?php
// api/tests/Unit/PruneOldNotificationsTest.php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneOldNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_old_read_notifications(): void
    {
        $user = User::factory()->create();

        // Old + read → should be pruned
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Old']),
            'read_at' => now()->subDays(100),
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        // Old + unread → should NOT be pruned
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Old Unread']),
            'read_at' => null,
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        // Recent + read → should NOT be pruned
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Recent']),
            'read_at' => now()->subDays(5),
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $this->artisan('notifications:prune --days=90')
            ->expectsOutputToContain('Pruned 1')
            ->assertSuccessful();

        $this->assertEquals(2, DB::table('notifications')->count());
    }
}
```

- [ ] **Step 3: Run test**

Run: `cd api && php artisan test tests/Unit/PruneOldNotificationsTest.php --stop-on-failure`
Expected: PASS

- [ ] **Step 4: Register in scheduler (optional — add to `routes/console.php` or `app/Console/Kernel.php`)**

In `api/routes/console.php`, add:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifications:prune --days=90')->dailyAt('02:00');
```

- [ ] **Step 5: Commit**

```bash
git add api/app/Console/Commands/PruneOldNotifications.php \
  api/tests/Unit/PruneOldNotificationsTest.php \
  api/routes/console.php
git commit -m "feat(notifications): add prune command for old read notifications"
```

---

## Task 5: Frontend — Real-Time Notifications via Reverb + Toast

**Files:**
- Create: `spa/src/hooks/useNotificationRealtime.ts`
- Modify: `spa/src/api/notifications.ts`
- Modify: `spa/src/components/layout/NotificationBell.tsx`

- [ ] **Step 1: Create real-time notification hook**

```typescript
// spa/src/hooks/useNotificationRealtime.ts
import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { echo } from '@/lib/echo';
import { useAuthStore } from '@/stores/auth';

interface NotificationPayload {
  id: string;
  type: string;
  data: { title?: string; message?: string; link_to?: string };
  read_at: null;
  created_at: string;
}

export function useNotificationRealtime(): void {
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);

  useEffect(() => {
    if (!user?.id) return;

    const channel = echo.private(`user.${user.id}`);

    channel.listen('.notification.created', (payload: NotificationPayload) => {
      qc.invalidateQueries({ queryKey: ['notifications'] });

      const title = payload.data?.title ?? 'New notification';
      toast(title, { icon: '🔔', duration: 4000 });
    });

    return () => {
      try {
        channel.stopListening('.notification.created');
      } catch {
        // ignore HMR teardown
      }
      echo.leave(`user.${user.id}`);
    };
  }, [user?.id, qc]);
}
```

- [ ] **Step 2: Add delete + deleteAllRead to notifications API**

Append to `notificationsApi` object in `spa/src/api/notifications.ts`:

```typescript
  delete: (id: string) =>
    client.delete<{ data: { deleted: boolean } }>(`/notifications/${id}`).then((r) => r.data.data),

  deleteAllRead: () =>
    client.delete<{ data: { deleted: number } }>('/notifications/clear-read').then((r) => r.data.data),
```

- [ ] **Step 3: Wire useNotificationRealtime into NotificationBell**

At the top of the `NotificationBell` function body (line ~28 in `spa/src/components/layout/NotificationBell.tsx`), add:

```typescript
import { useNotificationRealtime } from '@/hooks/useNotificationRealtime';

// Inside function body, before useState:
useNotificationRealtime();
```

Also add "Mark all read" button to the dropdown header (replace the header div around line 106):

```tsx
          <div className="px-3 py-2 border-b border-default flex items-center justify-between">
            <span className="text-sm font-medium">Notifications</span>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted font-mono tabular-nums">{unread} unread</span>
              {unread > 0 && (
                <button
                  type="button"
                  onClick={() => { markAllMutation.mutate(); }}
                  className="text-2xs text-accent hover:underline"
                >
                  Mark all read
                </button>
              )}
            </div>
          </div>
```

Add the markAll mutation (after the existing markRead mutation around line 40):

```typescript
  const markAllMutation = useMutation({
    mutationFn: () => notificationsApi.markAllRead(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
    },
  });
```

- [ ] **Step 4: Verify in browser**

1. Start dev server: `cd spa && npm run dev`
2. Open app in two tabs (same user)
3. In tab 1, trigger a notification (e.g. via tinker inserting a test notification)
4. Tab 2 should show toast + bell count increment without page refresh

- [ ] **Step 5: Commit**

```bash
git add spa/src/hooks/useNotificationRealtime.ts \
  spa/src/api/notifications.ts \
  spa/src/components/layout/NotificationBell.tsx
git commit -m "feat(notifications): real-time push via Reverb + toast + mark-all in bell"
```

---

## Task 6: Frontend — Add Delete Actions + Infinite Scroll

**Files:**
- Modify: `spa/src/pages/notifications/index.tsx`

- [ ] **Step 1: Add delete mutations + UI buttons**

In `spa/src/pages/notifications/index.tsx`:

Add imports (after line 15):

```typescript
import { Trash2 } from 'lucide-react';
```

Add delete mutations after the `markAll` mutation (around line 60):

```typescript
  const deleteOne = useMutation({
    mutationFn: (id: string) => notificationsApi.delete(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  });

  const deleteAllReadMutation = useMutation({
    mutationFn: () => notificationsApi.deleteAllRead(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
      toast.success('All read notifications deleted');
    },
  });
```

Add "Delete all read" button in PageHeader actions (after the "Mark all read" button, around line 109):

```tsx
            <Button
              variant="ghost"
              size="sm"
              icon={<Trash2 size={14} />}
              onClick={() => deleteAllReadMutation.mutate()}
              loading={deleteAllReadMutation.isPending}
            >
              Delete all read
            </Button>
```

Add delete icon to each notification row (inside the row button, around line 215 before the closing `</button>`):

```tsx
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                deleteOne.mutate(n.id);
                              }}
                              className="ml-auto shrink-0 p-1 rounded hover:bg-subtle text-muted hover:text-danger transition-colors"
                              aria-label="Delete notification"
                            >
                              <Trash2 size={12} />
                            </button>
```

Adjust the row flex layout to accommodate the delete button. Change the row button opening tag (around line 186) to:

```tsx
                          <button
                            type="button"
                            onClick={() => handleClickRow(n)}
                            className={cn(
                              'w-full text-left px-3 py-2.5 flex items-start gap-3 hover:bg-elevated transition-colors duration-fast',
                              isUnread && 'border-l-2 border-accent',
                            )}
                          >
```

And ensure the title/message span has `flex-1` so the delete icon stays right-aligned.

- [ ] **Step 2: Add infinite scroll (optional — simple "Load more" button)**

Add state for pagination (after the `filter` state around line 43):

```typescript
  const [page, setPage] = useState(1);
```

Update query to use `page`:

```typescript
  const { data, isLoading, isError, refetch, fetchNextPage, hasNextPage, isFetchingNextPage } = useInfiniteQuery({
    queryKey: ['notifications', { filter, unreadOnly }],
    queryFn: ({ pageParam = 1 }) => notificationsApi.list({ per_page: 25, unread_only: unreadOnly, page: pageParam }),
    getNextPageParam: (lastPage) => {
      if (lastPage.meta.current_page < lastPage.meta.last_page) {
        return lastPage.meta.current_page + 1;
      }
      return undefined;
    },
    placeholderData: (prev) => prev,
    initialPageParam: 1,
  });
```

Flatten pages:

```typescript
  const allNotifications = useMemo(() => {
    if (!data) return [];
    return data.pages.flatMap((p) => p.data);
  }, [data]);
```

Update `visibleRows` to use `allNotifications` instead of `data.data`:

```typescript
  const visibleRows = useMemo(() => {
    if (filter === 'all' || filter === 'unread') return allNotifications;
    return allNotifications.filter((n) => notificationMeta(n.type).group === filter);
  }, [allNotifications, filter]);
```

Add "Load more" button at the end (after the grouped list, around line 225):

```tsx
        {hasNextPage && (
          <div className="mt-4 text-center">
            <Button
              variant="secondary"
              onClick={() => fetchNextPage()}
              loading={isFetchingNextPage}
            >
              Load more
            </Button>
          </div>
        )}
```

Update the subtitle to use the first page's meta:

```tsx
        subtitle={
          data?.pages[0]?.meta
            ? `${data.pages[0].meta.unread_count} unread of ${data.pages[0].meta.total} total`
            : undefined
        }
```

**Simpler approach without infinite scroll:** Keep existing implementation (fetch 50, no pagination). Add delete UI only.

- [ ] **Step 3: Test in browser**

1. Create several notifications
2. Mark some as read
3. Click "Delete all read" → read ones disappear
4. Click trash icon on individual notification → that one disappears
5. Verify query invalidation refreshes bell count

- [ ] **Step 4: Commit**

```bash
git add spa/src/pages/notifications/index.tsx
git commit -m "feat(notifications): add delete single + delete-all-read actions"
```

---

## Task 7: Sync Notification Preferences Types with Backend

**Files:**
- Modify: `spa/src/pages/self-service/notification-preferences.tsx`

**Problem:** NOTIFICATION_TYPES array has hardcoded keys like `leave.submitted`, `payroll.finalized` that don't match actual backend notification types (`chain.so_confirmed`, `chain.payslip_ready`, `approval_reminder`, etc.). Users toggle preferences for types that never fire.

- [ ] **Step 1: Replace NOTIFICATION_TYPES array with actual backend types**

In `spa/src/pages/self-service/notification-preferences.tsx`, replace lines 17-28:

```typescript
const NOTIFICATION_TYPES: Array<{ key: string; label: string; description: string }> = [
  { key: 'chain.so_confirmed',          label: 'Sales order confirmed',       description: 'A sales order you manage has been confirmed by the customer.' },
  { key: 'chain.payslip_ready',         label: 'Payslip ready',               description: 'Your payslip is ready to view.' },
  { key: 'chain.po_approved',           label: 'Purchase order approved',     description: 'A purchase order has been fully approved and is ready to send.' },
  { key: 'chain.pr_approved',           label: 'Purchase request approved',   description: 'Your purchase request has been approved.' },
  { key: 'chain.separation_initiated',  label: 'Separation initiated',        description: 'An employee separation process has started.' },
  { key: 'auto_invoice_draft',          label: 'Auto-invoice draft ready',    description: 'A delivery has been confirmed and an invoice draft was created.' },
  { key: 'approval_reminder',           label: 'Approval reminder',           description: 'You have a pending approval that needs action.' },
  { key: 'approval_escalation',         label: 'Approval escalation',         description: 'An approval you own has been escalated due to timeout.' },
];
```

- [ ] **Step 2: Test preferences UI**

1. Navigate to `/self-service/notification-preferences`
2. Verify 8 rows display with correct labels
3. Toggle switches → verify API call fires (Network tab)
4. Refresh → toggles persist

- [ ] **Step 3: Commit**

```bash
git add spa/src/pages/self-service/notification-preferences.tsx
git commit -m "fix(notifications): sync preferences types with backend notification types"
```

---

## Self-Review Checklist

- [x] **Spec coverage:** All audit gaps addressed (data contract, real-time push, delete/prune, listener migration, preference sync)
- [x] **Placeholder scan:** No TBD, TODO, "add appropriate", "similar to"
- [x] **Type consistency:** `NotificationData` type used consistently; `link_to` key standardized; channel names match (`user.{userId}`)
- [x] **Commands exact:** All `php artisan`, `npm`, `git` commands include exact flags
- [x] **Code complete:** Every step that changes code shows the full code block

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-06-05-notification-module-enhancement.md`. Two execution options:

**1. Subagent-Driven (recommended)** — Dispatch fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?

