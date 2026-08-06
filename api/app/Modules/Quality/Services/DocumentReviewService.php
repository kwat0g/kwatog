<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\NotificationService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\ControlledDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * T3.5.D — Periodic-review reminder cron.
 *
 * A doc is "due" when:
 *   review_interval_months IS NOT NULL
 *   AND is_active = true
 *   AND last_reviewed_at + interval_months < now()  (or last_reviewed_at IS NULL → due immediately)
 *
 * Idempotency gate (prevents permanent silence and re-fire spam):
 *   Fire when last_review_alert_at IS NULL
 *   OR last_review_alert_at < now() - 7 days  (re-arm after a week if still overdue)
 *   The fire stamps last_review_alert_at = now() in the same update.
 *
 * markReviewed() (in DocumentService) clears last_review_alert_at, which
 * naturally re-arms the gate the next time the formula goes overdue.
 *
 * Recipients: union of active users with role.slug = system_admin OR qc_inspector,
 *             deduplicated by user.id.
 */
class DocumentReviewService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{evaluated:int, alerts_sent:int}
     */
    public function check(): array
    {
        $now = CarbonImmutable::now();
        $rearmDays = $this->settings->get('quality.document_review.rearm_days');
        if (! is_numeric($rearmDays) || (int) $rearmDays <= 0) {
            throw new \App\Common\Exceptions\BusinessRuleException('Required business setting quality.document_review.rearm_days is missing or invalid.');
        }
        $rearmThreshold = $now->subDays((int) $rearmDays);

        $candidates = ControlledDocument::query()
            ->where('is_active', true)
            ->whereNotNull('review_interval_months')
            ->get();

        $evaluated = $candidates->count();
        $alerts    = 0;
        $recipients = $this->resolveRecipients();

        foreach ($candidates as $doc) {
            if (! $this->isOverdue($doc, $now)) {
                continue;
            }
            if (! $this->gateAllowsFire($doc, $rearmThreshold)) {
                continue;
            }
            if ($recipients->isEmpty()) {
                // Still stamp so we don't burn the gate every minute on a
                // misconfigured deployment with no recipients.
                $doc->forceFill(['last_review_alert_at' => $now])->save();
                continue;
            }

            $this->notify($doc, $recipients);
            $doc->forceFill(['last_review_alert_at' => $now])->save();
            $alerts++;
        }

        return ['evaluated' => $evaluated, 'alerts_sent' => $alerts];
    }

    private function isOverdue(ControlledDocument $doc, CarbonImmutable $now): bool
    {
        if ($doc->last_reviewed_at === null) {
            return true;
        }
        $due = CarbonImmutable::parse($doc->last_reviewed_at)
            ->addMonths((int) $doc->review_interval_months);
        return $due->lessThan($now);
    }

    private function gateAllowsFire(ControlledDocument $doc, CarbonImmutable $rearmThreshold): bool
    {
        if ($doc->last_review_alert_at === null) {
            return true;
        }
        return CarbonImmutable::parse($doc->last_review_alert_at)
            ->lessThan($rearmThreshold);
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(): Collection
    {
        $roles = array_values(array_filter(
            (array) $this->settings->get('quality.document_review.recipient_roles', []),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));

        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
            ->get()
            ->unique('id')
            ->values();
    }

    private function notify(ControlledDocument $doc, Collection $recipients): void
    {
        $title = "Document review due: {$doc->code}";
        $message = sprintf(
            '%s — %s. Review interval: %d months. Last reviewed: %s.',
            $doc->code,
            $doc->title,
            (int) $doc->review_interval_months,
            $doc->last_reviewed_at?->toDateString() ?? 'never',
        );

        $this->notifications->send($recipients, 'document.review_due', [
            'title'       => $title,
            'message'     => $message,
            'link_to'     => "/quality/documents/{$doc->hash_id}",
            'entity_type' => 'controlled_document',
            'entity_id'   => $doc->hash_id,
        ]);
    }
}
