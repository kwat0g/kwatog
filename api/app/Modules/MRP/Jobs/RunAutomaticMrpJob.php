<?php

declare(strict_types=1);

namespace App\Modules\MRP\Jobs;

use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Services\MrpAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class RunAutomaticMrpJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 900;

    public int $uniqueFor = 900;

    /** @var list<int> */
    public readonly array $salesOrderIds;

    public readonly string $reason;

    /** @param list<int> $salesOrderIds */
    public function __construct(array $salesOrderIds, string $reason)
    {
        $this->salesOrderIds = array_values(array_unique(array_map('intval', $salesOrderIds)));
        $this->reason = $reason;
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
        $automation->run(
            $this->salesOrderIds,
            MrpRunTrigger::Automatic,
            null,
            $this->reason,
        );
    }
}
