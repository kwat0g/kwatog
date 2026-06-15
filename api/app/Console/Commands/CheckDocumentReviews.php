<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Quality\Services\DocumentReviewService;
use Illuminate\Console\Command;

/**
 * T3.5.D — Daily check for documents whose periodic-review window has lapsed.
 * Idempotent: re-runs the same day are no-ops thanks to last_review_alert_at.
 */
class CheckDocumentReviews extends Command
{
    protected $signature = 'docs:check-reviews';
    protected $description = 'Notify QC + system admins of controlled documents whose review window has lapsed.';

    public function handle(DocumentReviewService $svc): int
    {
        $r = $svc->check();
        $this->info(sprintf(
            'Document review check: %d evaluated, %d alerts sent.',
            $r['evaluated'],
            $r['alerts_sent'],
        ));
        return self::SUCCESS;
    }
}
