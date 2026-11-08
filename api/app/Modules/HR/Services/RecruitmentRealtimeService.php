<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\OutboxService;
use App\Modules\HR\Events\RecruitmentChanged;
use App\Modules\HR\Models\ApplicationInterview;
use App\Modules\HR\Models\ApplicationNote;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages content-free refresh signals and coalesces writes by aggregate/transaction. */
final class RecruitmentRealtimeService
{
    public function stagePosting(JobPosting $posting, string $action): void
    {
        $this->stage(
            'job_posting',
            (string) $posting->hash_id,
            [],
            $action,
            $posting->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            (int) $posting->getKey(),
        );
    }

    public function stageApplication(JobApplication $application, string $action): void
    {
        $postingId = (int) $application->job_posting_id;
        $postingHashId = $postingId > 0
            ? app('hashids')->encode($postingId)
            : null;
        $related = is_string($postingHashId) && $postingHashId !== ''
            ? [['entity_type' => 'job_posting', 'hash_id' => $postingHashId]]
            : [];

        $this->stage(
            'job_application',
            (string) $application->hash_id,
            $related,
            $action,
            $application->updated_at?->format('Y-m-d\\TH:i:s.uP') ?? now()->format('Y-m-d\\TH:i:s.uP'),
            (int) $application->getKey(),
        );
    }

    public function stageInterview(ApplicationInterview $interview, string $action): void
    {
        $application = $interview->application()->first();
        if ($application instanceof JobApplication) {
            $this->stageApplication($application, $action);
        }
    }

    public function stageNote(ApplicationNote $note, string $action): void
    {
        $application = $note->application()->first();
        if ($application instanceof JobApplication) {
            $this->stageApplication($application, $action);
        }
    }

    /**
     * @param list<array{entity_type: string, hash_id: string}> $related
     */
    private function stage(
        string $entityType,
        string $entityId,
        array $related,
        string $action,
        string $version,
        int $aggregateId,
    ): void {
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();

        app(OutboxService::class)->recordCoalesced(new RecruitmentChanged(
            entityType: $entityType,
            entityId: $entityId,
            related: $related,
            action: $action,
            version: $version,
        ), "recruitment-refresh:{$entityType}:{$aggregateId}:{$transactionId}");
    }
}
