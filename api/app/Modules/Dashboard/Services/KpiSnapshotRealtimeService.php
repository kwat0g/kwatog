<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\OutboxService;
use App\Modules\Dashboard\Events\KpiSnapshotChanged;
use Illuminate\Support\Str;

/** Emits one authorized scorecard refresh for each completed compute batch. */
final class KpiSnapshotRealtimeService
{
    public function stage(int $year, int $month): void
    {
        $version = sprintf('%04d-%02d:%s', $year, $month, now()->format('Y-m-d\TH:i:s.uP'));

        app(OutboxService::class)->recordCoalesced(
            new KpiSnapshotChanged($version),
            'kpi-snapshot-refresh:'.Str::uuid(),
        );
    }
}
