<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\Maintenance\Services\DowntimeAnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckMaintenanceRepeatBreakdowns extends Command
{
    protected $signature = 'maintenance:check-repeat-breakdowns';

    protected $description = 'Notify maintenance supervisors about repeated machine breakdowns';

    public function handle(DowntimeAnalyticsService $analytics, NotificationService $notifications): int
    {
        $repeats = $analytics->repeatBreakdowns();
        $supervisors = User::query()->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', 'maintenance_supervisor'))
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission('maintenance.view'))
            ->values();
        if ($repeats !== [] && $supervisors->isEmpty()) {
            Log::error('Maintenance repeat-breakdown setup failure: no active maintenance supervisor.');

            return self::FAILURE;
        }
        foreach ($repeats as $repeat) {
            $machine = $repeat['machine'];
            $history = '/maintenance/downtime?search='.rawurlencode($machine->machine_code);
            $schedules = '/maintenance/schedules?maintainable_type=machine&maintainable_id='.$machine->hash_id;
            $notifications->send($supervisors, 'maintenance.repeat_breakdown', [
                'title' => 'Repeated breakdowns: '.$machine->machine_code,
                'message' => $repeat['count'].' distinct breakdown incidents in 30 days. Review machine history and its preventive schedule.',
                'link_to' => $history, 'history_link' => $history, 'schedule_link' => $schedules,
                'entity_type' => 'machine', 'entity_id' => $machine->hash_id,
            ], 'maintenance:repeat:'.$machine->id.':'.$repeat['crossing_id']);
        }

        return self::SUCCESS;
    }
}
