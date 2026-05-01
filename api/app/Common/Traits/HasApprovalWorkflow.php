<?php

declare(strict_types=1);

namespace App\Common\Traits;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Mix-in for any model that participates in an approval workflow.
 */
trait HasApprovalWorkflow
{
    public function approvalRecords(): MorphMany
    {
        return $this->morphMany(ApprovalRecord::class, 'approvable')->orderBy('step_order');
    }

    public function submitForApproval(string $workflowType, ?float $amount = null): void
    {
        app(ApprovalService::class)->submit($this, $workflowType, $amount);
    }

    public function nextApprovalStep(): ?ApprovalRecord
    {
        return app(ApprovalService::class)->nextStep($this);
    }

    public function isFullyApproved(): bool
    {
        return app(ApprovalService::class)->isFullyApproved($this);
    }
}
