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

        if ($hr->isEmpty()) {
            $this->error("No active hr_officer to notify about {$count} open attendance row(s).");
            Log::error('attendance:notify-missing-timeout has no recipient', ['open_count' => $count]);

            return self::FAILURE;
        }

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
