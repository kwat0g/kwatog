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
 * Tells HR and department heads which employees have a time in but no time out for yesterday.
 * An open row computes zero hours, so they must correct it by hand — never auto-closed,
 * because inventing a time out invents pay data.
 *
 * Distinguishes nothing-to-do (SUCCESS) from everything-failed (FAILURE).
 */
class NotifyMissingTimeOut extends Command
{
    protected $signature = 'attendance:notify-missing-timeout';

    protected $description = 'Notify HR and department heads of yesterday\'s attendance rows with a time in but no time out';

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
            ->get()
            ->filter(fn (User $user): bool => $user->hasPermission('attendance.view')
                && ($user->hasPermission('attendance.edit') || $user->hasPermission('attendance.import')))
            ->values();

        if ($hr->isEmpty()) {
            $this->error("No active hr_officer to notify about {$count} open attendance row(s).");
            Log::error('attendance:notify-missing-timeout has no recipient', ['open_count' => $count]);

            return self::FAILURE;
        }

        $label = $yesterday->format('M j, Y');
        $linkTo = '/hr/attendance?missing_time_out=1';
        $failed = 0;
        $sent = 0;

        try {
            $notifications->sendForSubject($open->first(), $hr, 'attendance.missing_time_out', [
                'title' => 'Missing time-out',
                'message' => "{$count} employee(s) have no time out for {$label}.",
                'link_to' => $linkTo,
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
                ->get()
                ->filter(fn (User $user): bool => $user->hasPermission('attendance.view')
                    && $user->hasPermission('attendance.ot.approve'))
                ->values();
            $department = $rows->first()->employee?->department?->name ?? "department {$departmentId}";

            if ($heads->isEmpty()) {
                $this->warn("{$department}: no active department head to notify about {$rows->count()} open row(s).");

                continue;
            }

            foreach ($heads as $head) {
                try {
                    $notifications->sendForSubject($rows->first(), $head, 'attendance.missing_time_out', [
                        'title' => 'Missing time-out',
                        'message' => "{$rows->count()} employee(s) in {$department} have no time out for {$label}.",
                        'link_to' => $linkTo,
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
}
