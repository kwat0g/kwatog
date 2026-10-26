<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyUpcomingWorkOrders extends Command
{
    protected $signature = 'production:notify-upcoming-work-orders';

    protected $description = 'Notify current crews and production owners about upcoming or missed approved starts';

    public function handle(WorkOrderAssignmentService $assignments, NotificationService $notifications): int
    {
        $owners = User::where('is_active', true)->whereHas('role', fn ($query) => $query->whereIn('slug', ['ppc_head', 'production_manager']))->get();
        WorkOrder::whereIn('status', ['confirmed', 'paused'])->whereHas('schedules', fn ($query) => $query
            ->whereNull('rework_ncr_id')->where('reserves_capacity', true)->where('is_confirmed', true)
            ->where('status', 'confirmed')->where('scheduled_start', '<=', now()->addMinutes(30))
            ->where('scheduled_end', '>', now()))->chunkById(100, function ($orders) use ($assignments, $notifications, $owners): void {
                foreach ($orders as $order) {
                    DB::transaction(function () use ($order, $assignments, $notifications, $owners): void {
                        $wo = WorkOrder::lockForUpdate()->find($order->id);
                        if (! $wo || ! in_array($wo->status->value, ['confirmed', 'paused'], true)) {
                            return;
                        }
                        $slot = $assignments->schedule($wo);
                        if (! $slot || ! $slot->is_confirmed || $slot->status->value !== 'confirmed'
                            || $slot->scheduled_start->gt(now()->addMinutes(30)) || $slot->scheduled_end->lte(now())) {
                            return;
                        }
                        $crew = $assignments->currentCrew($wo);
                        try {
                            if (! $crew) {
                                throw new BusinessRuleException('Assign workers and an output recorder.');
                            }
                            $remaining = $slot->replicate();
                            $remaining->scheduled_start = now()->max($slot->scheduled_start);
                            $assignments->assertScheduledCrew($wo, $remaining, allowHoliday: $assignments->holidayAuthorized($slot));
                        } catch (BusinessRuleException $error) {
                            $notifications->sendForSubject($wo, $owners, 'production.assignment.review', [
                                'title' => 'Production crew needs review: '.$wo->wo_number,
                                'message' => 'Worker availability changed. Check attendance, shift coverage and crew before Start.',
                                'link_to' => '/production/work-orders/'.$wo->hash_id,
                                'entity_type' => 'work_order', 'entity_id' => $wo->hash_id,
                            ], 'wo-crew-review-'.$slot->id.'-'.($crew?->id ?? 'none').'-'.sha1($error->getMessage()), emailEnabled: false);
                        }
                        $stage = $slot->scheduled_start->gt(now()) ? 'upcoming'
                            : (now()->gt($slot->scheduled_start->copy()->addMinutes(ProductionResourceService::START_GRACE_MINUTES)) ? 'missed' : 'due');
                        $when = $slot->scheduled_start->copy()->timezone(config('app.timezone'))->format('M j, g:i A').' ('.config('app.timezone').')';
                        $title = match ($stage) {
                            'upcoming' => 'Production starts soon: '.$wo->wo_number,
                            'due' => 'Production time reached: '.$wo->wo_number,
                            default => 'Production has not started: '.$wo->wo_number,
                        };
                        $message = match ($stage) {
                            'upcoming' => 'Scheduled for '.$when.'. Prepare your crew and materials.',
                            'due' => 'Scheduled for '.$when.'. Open the WO and check readiness before starting or resuming.',
                            default => 'Scheduled for '.$when.'. Check readiness now; use Reschedule if more time is needed.',
                        };
                        $envelope = ['title' => $title, 'message' => $message, 'entity_type' => 'work_order', 'entity_id' => $wo->hash_id];
                        $key = 'wo-slot-reminder-'.$slot->id.'-'.$slot->scheduled_start->getTimestamp().'-'.$stage;
                        $notifications->sendForSubject($wo, $owners, 'production.schedule.reminder', $envelope + [
                            'link_to' => '/production/work-orders/'.$wo->hash_id,
                        ], $key, emailEnabled: false);
                        $workers = $assignments->productionEmployees()->whereIn('id', $crew?->members->pluck('employee_id') ?? [])
                            ->get()->map(fn ($employee) => $employee->user);
                        $workerMessage = $stage === 'upcoming' ? $message
                            : 'Scheduled for '.$when.'. Contact Production management to '.($stage === 'due' ? 'start or resume this WO.' : 'check or reschedule this WO.');
                        $notifications->sendForSubject($wo, $workers, 'production.schedule.reminder', array_replace($envelope, [
                            'message' => $workerMessage,
                            'link_to' => '/self-service/work-orders/'.$wo->hash_id,
                        ]), $key, emailEnabled: false);
                    });
                }
            });
        $this->info('Upcoming work-order reminders checked.');

        return self::SUCCESS;
    }
}
