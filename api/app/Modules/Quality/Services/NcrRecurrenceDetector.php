<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Retryable recurrence detection for NCRs.
 *
 * New rows carry a SHA-256 signature of canonical defect data. Inspection
 * failures use the failed parameter/tolerance signature, so a generated NCR
 * number can never obscure the actual defect. Manual NCRs without an
 * inspection use a normalised full description as their fallback signature.
 */
class NcrRecurrenceDetector
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public static function descriptionSignature(string $text): string
    {
        $canonical = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));

        return hash('sha256', $canonical);
    }

    public static function signatureForInspection(Inspection $inspection): string
    {
        /** @var Collection<int, \App\Modules\Quality\Models\InspectionMeasurement> $measurements */
        $measurements = $inspection->relationLoaded('measurements')
            ? $inspection->measurements
            : $inspection->measurements()->where('is_pass', false)->get();

        $parts = $measurements
            ->filter(static fn ($measurement): bool => $measurement->is_pass === false)
            ->map(static function ($measurement): string {
                $parameterType = $measurement->parameter_type instanceof \BackedEnum
                    ? $measurement->parameter_type->value
                    : (string) $measurement->parameter_type;

                return implode(':', [
                    strtolower(trim((string) $measurement->parameter_name)),
                    strtolower(trim($parameterType)),
                    (string) ($measurement->nominal_value ?? ''),
                    (string) ($measurement->tolerance_min ?? ''),
                    (string) ($measurement->tolerance_max ?? ''),
                    $measurement->is_critical ? 'critical' : 'noncritical',
                ]);
            })
            ->sort()
            ->values()
            ->all();

        if ($parts === []) {
            $stage = $inspection->stage instanceof \BackedEnum
                ? $inspection->stage->value
                : (string) $inspection->stage;
            $parts[] = 'stage:'.strtolower(trim($stage));
            $parts[] = 'defect_count:'.(int) $inspection->defect_count;
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Link the NCR to the most recent same-product, same-signature NCR in the
     * configured window. Notification delivery is intentionally separate so a
     * notification failure can retry without rewriting the lineage.
     */
    public function scan(int|NonConformanceReport $ncr): void
    {
        $ncr = is_int($ncr)
            ? NonConformanceReport::query()->findOrFail($ncr)
            : $ncr->fresh();

        if (! $ncr || $ncr->recurrence_of_ncr_id || ! $ncr->product_id) {
            return;
        }

        $signature = (string) $ncr->defect_signature;
        if ($signature === '') {
            $inspection = $ncr->inspection_id
                ? Inspection::query()->with('measurements')->find($ncr->inspection_id)
                : null;
            $signature = $inspection
                ? self::signatureForInspection($inspection)
                : self::descriptionSignature((string) $ncr->defect_description);
            $ncr->forceFill(['defect_signature' => $signature])->save();
        }

        $windowDays = $this->windowDays();
        $prior = NonConformanceReport::query()
            ->where('product_id', $ncr->product_id)
            ->where('id', '!=', $ncr->id)
            ->where('defect_signature', $signature)
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->where(function ($query) use ($ncr): void {
                $query
                    ->where('created_at', '<', $ncr->created_at)
                    ->orWhere(fn ($sameTimestamp) => $sameTimestamp
                        ->where('created_at', $ncr->created_at)
                        ->where('id', '<', $ncr->id));
            })
            ->orderByDesc('created_at')
            ->first(['id']);

        // Rows created before the signature column was introduced have no
        // persisted signature. Recompute their structured inspection defect
        // (or manual-description fallback) so the migration does not create a
        // permanent recurrence blind spot.
        if (! $prior) {
            $prior = NonConformanceReport::query()
                ->with('inspection.measurements')
                ->where('product_id', $ncr->product_id)
                ->where('id', '!=', $ncr->id)
                ->whereNull('defect_signature')
                ->where('created_at', '>=', now()->subDays($windowDays))
                ->where(function ($query) use ($ncr): void {
                    $query
                        ->where('created_at', '<', $ncr->created_at)
                        ->orWhere(fn ($sameTimestamp) => $sameTimestamp
                            ->where('created_at', $ncr->created_at)
                            ->where('id', '<', $ncr->id));
                })
                ->orderByDesc('created_at')
                ->get(['id', 'inspection_id', 'defect_description'])
                ->first(fn (NonConformanceReport $candidate): bool => $this->signatureForExistingNcr($candidate) === $signature);
        }

        if (! $prior) {
            return;
        }

        DB::transaction(function () use ($ncr, $prior): void {
            $locked = NonConformanceReport::query()->lockForUpdate()->findOrFail($ncr->id);
            if ($locked->recurrence_of_ncr_id) {
                return;
            }

            $locked->forceFill(['recurrence_of_ncr_id' => $prior->id])->save();
            app(OutboxService::class)->record(
                new NcrRecurrenceLinked($locked->fresh()),
                'ncr:'.$locked->id.':recurrence:v1',
            );
        });
    }

    /**
     * Deliver the recurrence alert after the lineage transaction has settled.
     * The recurrence scan job owns the durable notification-sent marker and
     * calls this method again when a previous delivery failed.
     *
     * @return int number of active recipients handed to NotificationService
     */
    public function notify(NonConformanceReport $ncr): int
    {
        $ncr = $ncr->fresh();
        if (! $ncr || ! $ncr->recurrence_of_ncr_id || ! $ncr->product_id) {
            return 0;
        }

        $roles = array_values(array_filter(
            (array) $this->settings->get('quality.ncr.recurrence_notification_roles', []),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));
        if ($roles === []) {
            throw new BusinessRuleException(
                'Required setting quality.ncr.recurrence_notification_roles is missing or empty.'
            );
        }

        $recipients = User::query()
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
            ->where('is_active', true)
            ->get();
        if ($recipients->isEmpty()) {
            throw new BusinessRuleException(
                'No active recipients are available for NCR recurrence notifications.'
            );
        }

        $windowDays = $this->windowDays();
        $this->notifications->send($recipients, 'ncr.recurrence', [
            'title'       => 'Recurring NCR detected',
            'message'     => "NCR {$ncr->ncr_number} appears to recur a prior NCR within the last {$windowDays} days. Review for systemic corrective action.",
            'link_to'     => "/quality/ncrs/{$ncr->hash_id}",
            'entity_type' => 'ncr',
            'entity_id'   => $ncr->hash_id,
            'ncr_number'  => $ncr->ncr_number,
        ]);

        return $recipients->count();
    }

    private function windowDays(): int
    {
        $value = $this->settings->get('quality.ncr.recurrence_window_days');
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new BusinessRuleException(
                'Required business setting quality.ncr.recurrence_window_days is missing or invalid.'
            );
        }

        return (int) $value;
    }

    private function signatureForExistingNcr(NonConformanceReport $ncr): string
    {
        if ($ncr->inspection) {
            return self::signatureForInspection($ncr->inspection);
        }

        return self::descriptionSignature((string) $ncr->defect_description);
    }
}
