<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use Database\Factories\NonConformanceReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 7 — Task 61. Non-Conformance Report root row.
 */
class NonConformanceReport extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    protected $table = 'non_conformance_reports';

    protected static function newFactory(): NonConformanceReportFactory
    {
        return NonConformanceReportFactory::new();
    }

    protected $fillable = [
        'ncr_number', 'source', 'severity', 'status',
        'product_id', 'inspection_id', 'complaint_id',
        'defect_description', 'defect_signature', 'affected_quantity', 'disposition',
        'root_cause', 'corrective_action',
        'created_by', 'assigned_to', 'closed_by', 'closed_at',
        'replacement_work_order_id',
        'is_auto_generated', 'is_late_incoming', 'late_hold_quantity',
        'escalation_level', 'last_escalated_at', 'recurrence_of_ncr_id',
        'rework_work_order_id',
        // CAPA effectiveness loop.
        'effectiveness_status', 'effectiveness_closed_at',
        // MRB incoming failure disposition.
        'mrb_accepted_quantity', 'mrb_decided_by', 'mrb_decided_at',
    ];

    protected $casts = [
        'source' => NcrSource::class,
        'severity' => NcrSeverity::class,
        'status' => NcrStatus::class,
        'disposition' => NcrDisposition::class,
        'affected_quantity' => 'integer',
        'closed_at' => 'datetime',
        'is_auto_generated' => 'boolean',
        'is_late_incoming' => 'boolean',
        'late_hold_quantity' => 'decimal:3',
        'last_escalated_at' => 'datetime',
        // CAPA effectiveness loop.
        'effectiveness_status' => EffectivenessStatus::class,
        'effectiveness_closed_at' => 'datetime',
        // MRB incoming failure disposition.
        'mrb_accepted_quantity' => 'string',
        'mrb_decided_at' => 'datetime',
        'sorting_approved_at' => 'datetime',
        'sorting_inspected_quantity' => 'decimal:3',
        'sorting_uses_reviewed_qc' => 'boolean',
        'rework_started_at' => 'datetime',
        'rework_finished_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [NcrStatus::Open->value, NcrStatus::InProgress->value]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function mrbDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mrb_decided_by');
    }

    public function reworkStartedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rework_started_by');
    }

    public function reworkFinishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rework_finished_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(NcrAction::class, 'ncr_id')->orderBy('performed_at');
    }

    public function replacementWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'replacement_work_order_id');
    }

    public function recurrenceOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurrence_of_ncr_id');
    }

    public function recurrenceScans(): HasMany
    {
        return $this->hasMany(NcrRecurrenceScan::class, 'ncr_id');
    }

    public function reworkWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'rework_work_order_id');
    }

    public function scopeStatus(Builder $q, NcrStatus|string $status): Builder
    {
        return $q->where('status', $status instanceof NcrStatus ? $status->value : $status);
    }
}
