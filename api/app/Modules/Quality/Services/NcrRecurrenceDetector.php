<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\NotificationService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * T3.1.D — Recurrence detection.
 *
 * On NCR create, check the prior 30 days for an NCR on the same product
 * with the same defect signature. If found, link the new row via
 * `recurrence_of_ncr_id` and notify QC + plant managers.
 *
 * Defect signature = lowercased + whitespace-collapsed + first 80 chars
 * of `defect_description`. Cheap, deterministic, good enough for thesis.
 */
class NcrRecurrenceDetector
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function scan(NonConformanceReport $ncr): void
    {
        try {
            if ($ncr->recurrence_of_ncr_id) {
                return; // already linked — idempotent
            }
            $signature = $this->signature((string) $ncr->defect_description);
            if ($signature === '' || ! $ncr->product_id) {
                return;
            }

            $windowDays = $this->settings->get('quality.ncr.recurrence_window_days');
            if (! is_numeric($windowDays) || (int) $windowDays <= 0) {
                throw new \App\Common\Exceptions\BusinessRuleException('Required business setting quality.ncr.recurrence_window_days is missing or invalid.');
            }
            $prior = NonConformanceReport::query()
                ->where('product_id', $ncr->product_id)
                ->where('id', '!=', $ncr->id)
                ->where('created_at', '>=', now()->subDays((int) $windowDays))
                ->orderByDesc('created_at')
                ->get(['id', 'defect_description'])
                ->first(fn ($p) => $this->signature((string) $p->defect_description) === $signature);

            if (! $prior) {
                return;
            }

            DB::transaction(function () use ($ncr, $prior): void {
                $ncr->forceFill(['recurrence_of_ncr_id' => $prior->id])->save();
                app(OutboxService::class)->record(
                    new NcrRecurrenceLinked($ncr->fresh()),
                );
            });

            $notificationRoles = array_values(array_filter(
                (array) $this->settings->get('quality.ncr.recurrence_notification_roles', []),
                static fn ($role): bool => is_string($role) && $role !== '',
            ));
            $recipients = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $notificationRoles))
                ->where('is_active', true)
                ->get();
            foreach ($recipients as $user) {
                $this->notifications->send($user, 'ncr.recurrence', [
                    'title'   => 'Recurring NCR detected',
                    'message' => "NCR {$ncr->ncr_number} appears to recur a prior NCR within the last {$windowDays} days. Review for systemic corrective action.",
                    'link_to' => "/quality/ncrs/{$ncr->hash_id}",
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('NcrRecurrenceDetector: scan failed', [
                'ncr_id' => $ncr->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function signature(string $text): string
    {
        $text = strtolower(trim($text));
        $text = (string) preg_replace('/\s+/', ' ', $text);
        return substr($text, 0, 80);
    }
}
