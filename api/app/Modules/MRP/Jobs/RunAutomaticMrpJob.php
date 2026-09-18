<?php

declare(strict_types=1);

namespace App\Modules\MRP\Jobs;

use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Services\MrpAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Automatic, coalesced MRP + finite-capacity planning run.
 *
 * The job is unique per affected SO scope so repeated domain events do not
 * create a stack of identical plans. A plant-wide overlap fence protects the
 * shared stock-allocation ledger when different scopes arrive together.
 */
class RunAutomaticMrpJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A follow-up released by the plant overlap fence must outlive the first
    // 900-second run plus the 300-second lock grace period.
    public int $tries = 50;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 900;

    public int $uniqueFor = 900;

    /** @var list<int> */
    public readonly array $salesOrderIds;

    public readonly string $reason;

    public readonly ?int $initiatedBy;

    /** @param list<int> $salesOrderIds */
    public function __construct(array $salesOrderIds, string $reason, ?int $initiatedBy = null)
    {
        $ids = array_values(array_unique(array_map('intval', $salesOrderIds)));
        sort($ids);
        $this->salesOrderIds = $ids;
        $this->reason = $reason;
        $this->initiatedBy = $initiatedBy;
    }

    public function uniqueId(): string
    {
        return 'mrp-automatic:'.hash('sha256', implode(',', $this->salesOrderIds));
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('mrp-automatic-plant'))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(MrpAutomationService $automation): void
    {
        $run = $automation->run(
            $this->salesOrderIds,
            MrpRunTrigger::Automatic,
            $this->initiatedBy,
            $this->reason,
        );

        if ($run->status === MrpRunStatus::Failed) {
            throw new \RuntimeException(
                $run->error_message ?: 'Automatic MRP run failed without an error message.'
            );
        }
    }
}
