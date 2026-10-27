<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\SettingsService;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Shared NCR response-SLA clock, thresholds, and overdue scope. */
final class NcrSlaService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function hoursFor(NcrSeverity $severity): int
    {
        return $this->settings->requiredInt("quality.ncr.sla_{$severity->value}_hours", 1, 87600);
    }

    public function clockStart(NonConformanceReport $ncr): ?Carbon
    {
        return $ncr->last_escalated_at ?? $ncr->created_at;
    }

    public function dueAt(NonConformanceReport $ncr): ?Carbon
    {
        $severity = $ncr->severity instanceof NcrSeverity
            ? $ncr->severity
            : (NcrSeverity::tryFrom((string) $ncr->severity) ?? NcrSeverity::Medium);

        return $this->clockStart($ncr)?->copy()->addHours($this->hoursFor($severity));
    }

    public function overdueQuery(?NcrSeverity $severity = null): Builder
    {
        $now = now();
        $severities = $severity === null ? NcrSeverity::cases() : [$severity];

        return NonConformanceReport::query()->open()->where(function (Builder $query) use ($now, $severities): void {
            foreach ($severities as $case) {
                $cutoff = $now->copy()->subHours($this->hoursFor($case));

                $query->orWhere(function (Builder $severityQuery) use ($case, $cutoff): void {
                    $severityQuery->where('severity', $case->value)
                        ->whereRaw('COALESCE(last_escalated_at, created_at) <= ?', [$cutoff]);
                });
            }
        });
    }
}
