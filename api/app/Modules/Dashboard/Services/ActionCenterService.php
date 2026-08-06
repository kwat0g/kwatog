<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Models\Alert;
use App\Common\Services\ApprovalBoardService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Models\ActionCenterTask;
use App\Modules\Dashboard\Enums\ActionCategory;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A permission-aware, cross-module queue of work that needs attention.
 *
 * Each source is isolated so an optional module or a transient source failure
 * cannot prevent users from seeing the rest of their queue.
 */
class ActionCenterService
{
    public function __construct(
        private readonly ApprovalBoardService $approvals,
        private readonly SettingsService $settings,
    ) {}

    /** @return array<int, array{value:string,label:string}> */
    public function categoryOptions(): array
    {
        return array_map(
            static fn (ActionCategory $category): array => ['value' => $category->value, 'label' => $category->label()],
            ActionCategory::cases(),
        );
    }

    /** @return array{items: array<int, array<string, mixed>>, summary: array<string, mixed>, generated_at: string} */
    public function for(User $user): array
    {
        $items = [];

        $this->append($items, 'approvals', fn () => $this->approvalItems($user), $user, ['approvals.board.view']);
        $this->append($items, 'alerts', fn () => $this->alertItems(), $user, ['alerts.view']);
        $this->append($items, 'inspections', fn () => $this->inspectionItems(), $user, ['quality.view', 'quality.inspections.view']);
        $this->append($items, 'ncrs', fn () => $this->ncrItems(), $user, ['quality.view', 'quality.ncr.view']);
        $this->append($items, 'maintenance', fn () => $this->maintenanceItems(), $user, ['maintenance.view']);
        $this->append($items, 'production', fn () => $this->productionItems(), $user, ['production.work_orders.view']);
        $this->append($items, 'deliveries', fn () => $this->deliveryItems(), $user, ['supply_chain.view']);

        $items = $this->overlayTaskState($items);

        usort($items, function (array $a, array $b): int {
            $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

            return [$rank[$a['priority']], $a['is_overdue'] ? 0 : 1, $a['created_at'] ?? '']
                <=> [$rank[$b['priority']], $b['is_overdue'] ? 0 : 1, $b['created_at'] ?? ''];
        });

        $items = array_slice($items, 0, $this->settings->requiredInt('dashboard.action_center.max_items', 1, 1000));
        $byCategory = [];
        foreach ($items as $item) {
            $byCategory[$item['category']] = ($byCategory[$item['category']] ?? 0) + 1;
        }

        return [
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'critical' => count(array_filter($items, fn (array $item) => $item['priority'] === 'critical')),
                'high' => count(array_filter($items, fn (array $item) => $item['priority'] === 'high')),
                'overdue' => count(array_filter($items, fn (array $item) => $item['is_overdue'])),
                'owned_by_me' => count(array_filter($items, fn (array $item) => ($item['assigned_to']['id'] ?? null) === $user->hash_id)),
                'unassigned' => count(array_filter($items, fn (array $item) => $item['assigned_to'] === null)),
                'by_category' => $byCategory,
            ],
            'generated_at' => now()->toIso8601String(),
            'category_options' => $this->categoryOptions(),
        ];
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function overlayTaskState(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $tasks = ActionCenterTask::query()->with('assignee:id,name')
            ->whereIn('item_key', array_column($items, 'id'))->get()->keyBy('item_key');

        $visible = [];
        foreach ($items as $item) {
            $task = $tasks->get($item['id']);
            if ($task?->state === 'resolved') {
                continue;
            }
            if ($task?->state === 'snoozed' && $task->snoozed_until?->isFuture()) {
                continue;
            }

            if ($task?->due_at) {
                $item['due_at'] = $task->due_at->toIso8601String();
            } elseif (! $item['due_at'] && $item['created_at']) {
                $hours = $this->actionCenterSlaHours((string) $item['category']);
                $item['due_at'] = Carbon::parse($item['created_at'])->addHours($hours)->toIso8601String();
            }
            if ($item['due_at']) {
                $item['is_overdue'] = Carbon::parse($item['due_at'])->isPast();
            }

            $item['task_state'] = $task?->state ?? 'open';
            $item['task_state_label'] = $this->humanize((string) $item['task_state']);
            $item['assigned_to'] = $task?->assignee ? [
                'id' => $task->assignee->hash_id,
                'name' => $task->assignee->name,
            ] : null;
            $item['snoozed_until'] = $task?->snoozed_until?->toIso8601String();
            $visible[] = $item;
        }

        return $visible;
    }

    /** @param array<int, array<string, mixed>> $items @param array<int, string> $permissions */
    private function append(array &$items, string $source, callable $loader, User $user, array $permissions): void
    {
        if (! $this->hasAnyPermission($user, $permissions)) {
            return;
        }

        try {
            array_push($items, ...$loader());
        } catch (Throwable $e) {
            Log::warning("ActionCenterService source {$source} skipped", ['exception' => $e]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function approvalItems(User $user): array
    {
        $overdueHours = $this->settings->requiredInt('approvals.reminder_hours', 1);
        $criticalHours = $this->settings->requiredInt('approvals.escalation_hours', $overdueHours);
        return array_map(function (array $card) use ($overdueHours, $criticalHours): array {
            $age = (int) $card['age_hours'];

            return $this->item(
                id: 'approval:'.$card['type'].':'.$card['id'],
                category: 'approval',
                kind: (string) $card['type'],
                title: 'Approve '.$this->approvalLabel((string) $card['type']).' '.$card['number'],
                description: (string) ($card['summary'] ?: 'Approval is waiting for your role.'),
                reference: (string) $card['number'],
                priority: $age >= $criticalHours ? 'critical' : ($age >= $overdueHours ? 'high' : 'medium'),
                status: 'Waiting for you',
                link: (string) $card['link'],
                createdAt: (string) $card['since'],
                dueAt: null,
                overdue: $age >= $overdueHours,
                owner: $card['requester']['name'] ?? null,
            );
        }, $this->approvals->board($user)['my_action']);
    }

    /** @return array<int, array<string, mixed>> */
    private function alertItems(): array
    {
        return Alert::query()->active()->latest()->limit($this->sourceLimit())->get()->map(function (Alert $alert): array {
            $severity = $this->enumValue($alert->severity);

            return $this->item(
                id: 'alert:'.$alert->hash_id,
                category: 'alert',
                kind: $this->enumValue($alert->type),
                title: (string) $alert->title,
                description: (string) $alert->message,
                reference: null,
                priority: $severity === 'critical' ? 'critical' : ($severity === 'warning' ? 'high' : 'medium'),
                status: $alert->is_read ? 'Active' : 'New alert',
                link: '/alerts',
                createdAt: $alert->created_at?->toIso8601String(),
                dueAt: null,
                overdue: $severity === 'critical' && $alert->created_at?->lt(now()->subHours(4)),
                owner: null,
            );
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function inspectionItems(): array
    {
        $slaHours = $this->actionCenterSlaHours('quality');
        return Inspection::query()->with(['item:id,name', 'product:id,name', 'inspector:id,name'])
            ->whereIn('status', ['draft', 'in_progress'])->oldest()->limit($this->sourceLimit())->get()
            ->map(function (Inspection $inspection) use ($slaHours): array {
                $age = $inspection->created_at?->diffInHours(now()) ?? 0;
                $stage = $this->enumValue($inspection->stage);
                $subject = $inspection->item?->name ?? $inspection->product?->name ?? 'inspection lot';

                return $this->item(
                    id: 'quality:inspection:'.$inspection->hash_id,
                    category: 'quality', kind: 'inspection',
                    title: 'Complete '.$this->humanize($stage).' inspection',
                    description: $subject.' · sample size '.(int) $inspection->sample_size,
                    reference: $inspection->inspection_number,
                    priority: $stage === 'incoming' || $age >= $slaHours ? 'high' : 'medium',
                    status: $this->humanize($this->enumValue($inspection->status)),
                    link: '/quality/inspections/'.$inspection->hash_id,
                    createdAt: $inspection->created_at?->toIso8601String(), dueAt: null,
                    overdue: $age >= $slaHours,
                    owner: $inspection->inspector?->name,
                );
            })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function ncrItems(): array
    {
        return NonConformanceReport::query()->with(['product:id,name', 'assignee:id,name'])
            ->whereNotIn('status', ['closed', 'cancelled'])->oldest()->limit($this->sourceLimit())->get()
            ->map(function (NonConformanceReport $ncr): array {
                $priority = $this->enumValue($ncr->severity);
                $sla = match ($priority) {
                    'critical' => $this->settings->requiredInt('quality.ncr.sla_critical_hours', 1),
                    'high' => $this->settings->requiredInt('quality.ncr.sla_high_hours', 1),
                    'medium' => $this->settings->requiredInt('quality.ncr.sla_medium_hours', 1),
                    'low' => $this->settings->requiredInt('quality.ncr.sla_low_hours', 1),
                    default => $this->settings->requiredInt('quality.ncr.sla_medium_hours', 1),
                };
                $age = $ncr->created_at?->diffInHours(now()) ?? 0;

                return $this->item(
                    id: 'quality:ncr:'.$ncr->hash_id,
                    category: 'quality', kind: 'ncr',
                    title: 'Resolve NCR '.$ncr->ncr_number,
                    description: (string) $ncr->defect_description,
                    reference: $ncr->ncr_number,
                    priority: in_array($priority, ['critical', 'high', 'medium', 'low'], true) ? $priority : 'medium',
                    status: $this->humanize($this->enumValue($ncr->status)),
                    link: '/quality/ncrs/'.$ncr->hash_id,
                    createdAt: $ncr->created_at?->toIso8601String(),
                    dueAt: $ncr->created_at?->copy()->addHours($sla)->toIso8601String(),
                    overdue: $age >= $sla,
                    owner: $ncr->assignee?->name,
                );
            })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function maintenanceItems(): array
    {
        return MaintenanceWorkOrder::query()->with('assignee')->open()->oldest()->limit($this->sourceLimit())->get()
            ->map(function (MaintenanceWorkOrder $workOrder): array {
                $priority = $this->enumValue($workOrder->priority);
                $age = $workOrder->created_at?->diffInHours(now()) ?? 0;

                return $this->item(
                    id: 'maintenance:work-order:'.$workOrder->hash_id,
                    category: 'maintenance', kind: 'work_order',
                    title: 'Service '.$workOrder->mwo_number,
                    description: (string) $workOrder->description,
                    reference: $workOrder->mwo_number,
                    priority: in_array($priority, ['critical', 'high', 'medium', 'low'], true) ? $priority : 'medium',
                    status: $this->humanize($this->enumValue($workOrder->status)),
                    link: '/maintenance/work-orders/'.$workOrder->hash_id,
                    createdAt: $workOrder->created_at?->toIso8601String(), dueAt: null,
                    overdue: $age >= ($priority === 'critical'
                        ? $this->settings->requiredInt('action_center.production.critical_sla_hours', 1)
                        : $this->settings->requiredInt('action_center.production.default_sla_hours', 1)),
                    owner: $workOrder->assignee?->full_name,
                );
            })->all();
    }

    private function actionCenterSlaHours(string $category): int
    {
        return match ($category) {
            'maintenance' => $this->settings->requiredInt('action_center.maintenance.default_sla_hours', 1),
            'production' => $this->settings->requiredInt('action_center.production.default_sla_hours', 1),
            default => $this->settings->requiredInt('action_center.default_sla_hours', 1),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function productionItems(): array
    {
        $criticalHours = $this->settings->requiredInt('action_center.production.critical_sla_hours', 1);
        return WorkOrder::query()->with('product:id,name')->whereIn('status', ['confirmed', 'in_progress'])
            ->whereNotNull('planned_end')->where('planned_end', '<', now())->oldest('planned_end')->limit($this->sourceLimit())->get()
            ->map(function (WorkOrder $workOrder) use ($criticalHours): array {
                $hours = $workOrder->planned_end?->diffInHours(now()) ?? 0;

                return $this->item(
                    id: 'production:work-order:'.$workOrder->hash_id,
                    category: 'production', kind: 'overdue_work_order',
                    title: 'Recover overdue work order '.$workOrder->wo_number,
                    description: ($workOrder->product?->name ?? 'Production order').' · '.round($workOrder->progress_percentage, 1).'% complete',
                    reference: $workOrder->wo_number,
                    priority: $hours >= $criticalHours ? 'critical' : 'high',
                    status: $this->humanize($this->enumValue($workOrder->status)),
                    link: '/production/work-orders/'.$workOrder->hash_id,
                    createdAt: $workOrder->created_at?->toIso8601String(),
                    dueAt: $workOrder->planned_end?->toIso8601String(), overdue: true, owner: null,
                );
            })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function deliveryItems(): array
    {
        return Delivery::query()->with('driver:id,name')->whereIn('status', ['loading', 'in_transit', 'delivered'])
            ->oldest('scheduled_date')->limit($this->sourceLimit())->get()->map(function (Delivery $delivery): array {
                $status = $this->enumValue($delivery->status);
                $overdue = $delivery->scheduled_date?->lt(today()) && $status !== 'delivered';

                return $this->item(
                    id: 'supply-chain:delivery:'.$delivery->hash_id,
                    category: 'supply_chain', kind: 'delivery',
                    title: $status === 'delivered' ? 'Confirm delivery '.$delivery->delivery_number : 'Update delivery '.$delivery->delivery_number,
                    description: $status === 'delivered' ? 'Delivery is awaiting final confirmation.' : 'Keep dispatch status and proof of delivery current.',
                    reference: $delivery->delivery_number,
                    priority: $overdue ? 'high' : 'medium', status: $this->humanize($status),
                    link: '/supply-chain/deliveries/'.$delivery->hash_id,
                    createdAt: $delivery->created_at?->toIso8601String(),
                    dueAt: $delivery->scheduled_date?->toIso8601String(), overdue: (bool) $overdue,
                    owner: $delivery->driver?->name,
                );
            })->all();
    }

    private function sourceLimit(): int
    {
        return $this->settings->requiredInt('dashboard.action_center.source_limit', 1, 500);
    }

    /** @return array<string, mixed> */
    private function item(string $id, string $category, string $kind, string $title, string $description,
        ?string $reference, string $priority, string $status, string $link, ?string $createdAt,
        ?string $dueAt, bool $overdue, ?string $owner): array
    {
        $created = $createdAt ? Carbon::parse($createdAt) : null;

        return [
            'id' => $id, 'category' => $category, 'kind' => $kind, 'title' => $title,
            'description' => $description, 'reference' => $reference, 'priority' => $priority,
            'priority_label' => $this->humanize($priority),
            'status_label' => $status, 'link' => $link, 'created_at' => $createdAt,
            'due_at' => $dueAt, 'age_hours' => $created ? (int) $created->diffInHours(now()) : null,
            'is_overdue' => $overdue, 'owner_label' => $owner,
        ];
    }

    /** @param array<int, string> $permissions */
    private function hasAnyPermission(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function humanize(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    private function approvalLabel(string $kind): string
    {
        return ['pr' => 'purchase request', 'po' => 'purchase order', 'leave' => 'leave request',
            'loan' => 'employee loan', 'payroll' => 'payroll period'][$kind] ?? $kind;
    }
}
