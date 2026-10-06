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
