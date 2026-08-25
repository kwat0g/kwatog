<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Events\PermissionOverrideChanged;
use App\Common\Services\ActivityFeedService;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mirrors canonical domain events into the company-wide activity feed.
 *
 * The listener is deliberately attached at the event dispatcher boundary: a
 * producer does not need to know about the admin read model, and an outbox
 * replay goes through the same idempotent path as the original dispatch.
 */
class RecordActivityFromEvent
{
    public function __construct(private readonly ActivityFeedService $feed) {}

    /**
     * Laravel wildcard listeners receive the event name and its payload.
     * Keep the filter here so framework events do not become feed noise.
     *
     * @param  array<int, mixed>  $payload
     */
    public function handleWildcard(string $eventName, array $payload): void
    {
        if (! str_contains($eventName, '\\Events\\')
            || (! str_starts_with($eventName, 'App\\Modules\\')
                && ! in_array($eventName, [ChainStepAdvanced::class, PermissionOverrideChanged::class], true))) {
            return;
        }

        $event = $payload[0] ?? null;
        if (is_object($event)) {
            $this->handle($event);
        }
    }

    public function handle(object $event): void
    {
        try {
            $description = $this->describe($event);
            if ($description === null) {
                return;
            }

            $this->feed->record(
                type: $description['type'],
                action: $description['action'],
                subject: $description['subject'],
                summary: $description['summary'],
                detail: $description['detail'],
                link: $description['link'],
                severity: $description['severity'],
                idempotencyKey: $description['idempotency_key'],
            );
        } catch (Throwable $e) {
            // Activity is an observability side effect. A failure must be
            // visible to operators without turning a committed business event
            // into a failed request or a retry storm.
            Log::warning('Activity feed projection failed.', [
                'event' => $event::class,
                'exception' => $e,
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function describe(object $event): ?array
    {
        $class = $event::class;
        $short = class_basename($class);

        if ($event instanceof ChainStepAdvanced) {
            return $this->describeChainStep($event);
        }

        if ($event instanceof PermissionOverrideChanged) {
            return $this->describePermissionOverride($event);
        }

        if (! str_starts_with($class, 'App\\Modules\\') || ! str_contains($class, '\\Events\\')) {
            return null;
        }

        // Broadcast-only UI refreshes and high-frequency progress ticks are
        // not durable business milestones and would drown the feed.
        if (in_array($short, ['BadgesChanged', 'PayrollProgressEvent'], true)) {
            return null;
        }

        $properties = get_object_vars($event);
        $subject = $this->findSubject($properties);
        $detail = $this->scalarDetails($properties);
        $identity = $this->eventIdentity($class, $subject, $detail);

        // A model id, request id, or equivalent durable identity is required;
        // otherwise a replay cannot be distinguished from a fresh event.
        if ($identity === null) {
            return null;
        }

        $type = $this->activityType($short);
        $action = $this->activityAction($short, $properties);
        $display = $subject ? $this->subjectDisplay($subject) : null;
        $summary = $display
            ? $display.' — '.Str::headline($action)
            : Str::headline($action);

        return [
            'type' => $type,
            'action' => $action,
            'subject' => $subject,
            'summary' => Str::limit($summary, 200, ''),
            'detail' => $detail,
            'link' => $subject ? $this->subjectLink($subject) : null,
            'severity' => $this->severity($type, $short, $properties),
            'idempotency_key' => $identity,
        ];
    }

    /** @param array<string, mixed> $properties */
    private function findSubject(array $properties): ?Model
    {
        $preferred = [
            'salesOrder', 'purchaseRequest', 'purchaseOrder', 'workOrder',
            'delivery', 'grn', 'inspection', 'ncr', 'clearance', 'period',
            'invoice', 'officialReceipt', 'creditNote', 'bill', 'leaveRequest',
            'loan', 'overtimeRequest', 'returnRequest', 'snapshot', 'machine',
            'mold', 'item', 'movement', 'complaint', 'employee', 'output',
        ];

        foreach ($preferred as $name) {
            $value = $properties[$name] ?? null;
            if ($value instanceof Model && $value->getKey() !== null) {
                return $value;
            }
        }

        foreach ($properties as $value) {
            if ($value instanceof Model && $value->getKey() !== null) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $properties */
    private function scalarDetails(array $properties): array
    {
        $detail = [];
        foreach ($properties as $key => $value) {
            if ($value instanceof Model) {
                continue;
            }

            $normalized = $this->normalizeDetail($value);
            if ($normalized !== null) {
                $detail[$key] = $normalized;
            }
        }

        return $detail;
    }

    private function normalizeDetail(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (is_scalar($value)) {
            return is_string($value) ? Str::limit($value, 500, '') : $value;
        }
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 20, true) as $key => $item) {
                $normalized = $this->normalizeDetail($item);
                if ($normalized !== null) {
                    $result[(string) $key] = $normalized;
                }
            }
            return $result;
        }

        return null;
    }

    private function eventIdentity(string $class, ?Model $subject, array $detail): ?string
    {
        $subjectIdentity = $subject ? [
            'type' => $subject->getMorphClass(),
            'id' => (int) $subject->getKey(),
            // A repeated dispatch of the same model instance is a replay; a
            // later real transition normally has a different update stamp.
            'updated_at' => $subject->getAttribute('updated_at'),
        ] : null;

        $requestIdentity = $detail['requestId']
            ?? $detail['request_id']
            ?? $detail['claimToken']
            ?? $detail['runId']
            ?? $detail['run_id']
            ?? null;

        if ($subjectIdentity === null && $requestIdentity === null) {
            return null;
        }

        return hash('sha256', json_encode([
            'event' => $class,
            'subject' => $subjectIdentity,
            'identity' => $requestIdentity,
            'detail' => $detail,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function activityType(string $short): string
    {
        if (preg_match('/Breakdown|Limit|LowStock|Deterioration|InspectionFailed/i', $short)) {
            return 'alert';
        }
        if (preg_match('/Approved|Rejected|Submitted|Decided|PendingHR/i', $short)) {
            return 'approval';
        }
        if (preg_match('/Requested|Generated|Replan/i', $short)) {
            return 'automation';
        }

        return 'transaction';
    }

    /** @param array<string, mixed> $properties */
    private function activityAction(string $short, array $properties): string
    {
        $base = Str::snake($short);

        if (array_key_exists('approved', $properties) && is_bool($properties['approved'])) {
            $base = Str::snake((string) preg_replace('/Decided$/', '', $short));
            return $base.'_'.($properties['approved'] ? 'approved' : 'rejected');
        }

        if (isset($properties['action']) && is_string($properties['action'])) {
            $base = Str::snake((string) preg_replace('/Updated$/', '', $short));
            return $base.'_'.Str::snake($properties['action']);
        }

        return $base;
    }

    /** @param array<string, mixed> $properties */
    private function severity(string $type, string $short, array $properties): string
    {
        if ($type === 'alert' || preg_match('/Rejected|Failed|Breakdown|LimitReached|Cancelled/i', $short)) {
            return 'danger';
        }
        if (preg_match('/Approved|Confirmed|Finalized|Passed|Completed|Issued|Disbursed/i', $short)) {
            return 'success';
        }
        if (isset($properties['approved']) && $properties['approved'] === false) {
            return 'danger';
        }

        return 'info';
    }

    private function subjectDisplay(Model $subject): string
    {
        $attributes = $subject->getAttributes();
        $label = class_basename($subject::class);
        foreach ([
            'employee_no', 'so_number', 'po_number', 'pr_number', 'wo_number',
            'grn_number', 'invoice_no', 'bill_no', 'mwo_number', 'rma_number',
            'ncr_number', 'inspection_no', 'document_no', 'code', 'name',
        ] as $key) {
            if (isset($attributes[$key]) && (string) $attributes[$key] !== '') {
                return $label.' '.(string) $attributes[$key];
            }
        }

        return $label.' #'.(string) $subject->getKey();
    }

    private function subjectLink(Model $subject): string
    {
        $hashId = app('hashids')->encode((int) $subject->getKey());
        $basename = class_basename($subject::class);
        $segments = [
            'SalesOrder' => 'crm/sales-orders',
            'PurchaseRequest' => 'purchasing/purchase-requests',
            'PurchaseOrder' => 'purchasing/purchase-orders',
            'WorkOrder' => 'production/work-orders',
            'Delivery' => 'supply-chain/deliveries',
            'Inspection' => 'quality/inspections',
            'NonConformanceReport' => 'quality/ncrs',
            'LeaveRequest' => 'hr/leaves',
            'EmployeeLoan' => 'hr/loans',
            'OvertimeRequest' => 'hr/attendance/overtime',
            'PayrollPeriod' => 'payroll/periods',
            'Invoice' => 'accounting/invoices',
            'Bill' => 'accounting/bills',
            'Employee' => 'hr/employees',
            'MaintenanceWorkOrder' => 'maintenance/work-orders',
            'Machine' => 'mrp/machines',
            'Mold' => 'mrp/molds',
            'MrpPlan' => 'mrp/plans',
            'GoodsReceiptNote' => 'inventory/grn',
            'Item' => 'inventory/items',
        ];

        if (isset($segments[$basename])) {
            return '/'.$segments[$basename].'/'.$hashId;
        }

        return '/admin/audit-logs/entity?'.http_build_query([
            'model_type' => $basename,
            'model_id' => $hashId,
        ]);
    }

    /** @return array<string, mixed> */
    private function describeChainStep(ChainStepAdvanced $event): array
    {
        $entity = strtolower($event->entityType);
        $label = $event->docNumber !== '' ? $event->docNumber : strtoupper($entity);

        return [
            'type' => 'transaction',
            'action' => 'chain_step_advanced',
            'subject' => null,
            'summary' => Str::limit($label.' — chain advanced to '.Str::headline($event->activeStep), 200, ''),
            'detail' => [
                'entity_type' => $event->entityType,
                'entity_id' => $event->entityHashId,
                'new_status' => $event->newStatus,
                'active_step' => $event->activeStep,
                'completed_steps' => $event->completedSteps,
            ],
            'link' => $this->chainLink($entity, $event->entityHashId),
            'severity' => 'success',
            'idempotency_key' => hash('sha256', json_encode([
                'event' => ChainStepAdvanced::class,
                'entity' => $entity,
                'id' => $event->entityHashId,
                'status' => $event->newStatus,
                'step' => $event->activeStep,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array<string, mixed> */
    private function describePermissionOverride(PermissionOverrideChanged $event): array
    {
        $hashId = app('hashids')->encode($event->userId);

        return [
            'type' => 'auth',
            'action' => 'permission_override_changed',
            'subject' => ['App\\Modules\\Auth\\Models\\User', $event->userId],
            'summary' => Str::limit('User '.$hashId.' — permission override changed', 200, ''),
            'detail' => [
                'permission_slug' => $event->permissionSlug,
                'old_type' => $event->oldType?->value,
                'new_type' => $event->newType?->value,
                'reason' => Str::limit($event->reason, 500, ''),
            ],
            'link' => '/admin/users/'.$hashId,
            'severity' => 'warning',
            'idempotency_key' => hash('sha256', json_encode([
                'event' => PermissionOverrideChanged::class,
                'user_id' => $event->userId,
                'permission' => $event->permissionSlug,
                'old_type' => $event->oldType?->value,
                'new_type' => $event->newType?->value,
                'reason' => $event->reason,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    private function chainLink(string $entity, string $hashId): ?string
    {
        $segments = [
            'sales_order' => 'crm/sales-orders',
            'purchase_order' => 'purchasing/purchase-orders',
            'work_order' => 'production/work-orders',
            'delivery' => 'supply-chain/deliveries',
        ];

        return isset($segments[$entity]) ? '/'.$segments[$entity].'/'.$hashId : null;
    }
}
