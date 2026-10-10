<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Enums\LateProductionReportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LateProductionReport extends Model
{
    use HasHashId;

    protected $guarded = ['id'];

    protected $casts = [
        'production_started_at' => 'datetime',
        'production_finished_at' => 'datetime',
        'good_count' => 'integer',
        'reject_count' => 'integer',
        'defects' => 'array',
        'crew_snapshot' => 'array',
        'evidence_summary' => 'array',
        'status' => LateProductionReportStatus::class,
        'reviewed_at' => 'datetime',
    ];

    private const IMMUTABLE_FIELDS = [
        'work_order_id', 'recorded_by', 'work_order_crew_id', 'crew_snapshot', 'source_reference',
        'production_started_at', 'production_finished_at', 'good_count', 'reject_count', 'defects',
        'reason', 'idempotency_key', 'idempotency_fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $report): void {
            if ($report->isDirty(self::IMMUTABLE_FIELDS)) {
                throw new BusinessRuleException('Late production report proposal is immutable. Submit a new report for a distinct source batch.');
            }
        });
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function crew(): BelongsTo
    {
        return $this->belongsTo(WorkOrderCrew::class, 'work_order_crew_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOutput::class, 'work_order_output_id');
    }
}
