<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderOutput extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'work_order_id', 'recorded_by', 'recorded_at',
        'good_count', 'reject_count', 'shift', 'batch_code', 'remarks',
        'idempotency_key', 'idempotency_fingerprint',
        'production_receipt_handoff_status', 'production_receipt_handoff_message',
        'production_receipt_handoff_at', 'production_receipt_movement_id',
        'material_lineage', 'receipt_unit_cost',
        'rework_ncr_id', 'rework_source_output_id',
        'work_order_crew_id', 'crew_snapshot', 'recording_reason',
        'material_handover_exception',
        'trial_inspection_id', 'trial_quantity', 'trial_settled_at',
        'held_quantity', 'held_settled_at', 'production_started_at', 'production_finished_at',
    ];

    protected $casts = [
        'held_quantity' => 'integer', 'held_settled_at' => 'datetime',
        'production_started_at' => 'datetime', 'production_finished_at' => 'datetime',
        'recorded_at' => 'datetime',
        'trial_inspection_id' => 'integer',
        'trial_quantity' => 'integer',
        'trial_settled_at' => 'datetime',
        'receipt_unit_cost' => 'decimal:4',
        'good_count' => 'integer',
        'reject_count' => 'integer',
        'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::class,
        'production_receipt_handoff_at' => 'datetime',
        'production_receipt_movement_id' => 'integer',
        'material_lineage' => 'array',
        'crew_snapshot' => 'array',
        'material_handover_exception' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $output): void {
            if ($output->getOriginal('production_started_at') !== null
                && $output->isDirty(['production_started_at', 'production_finished_at', 'work_order_id', 'work_order_crew_id', 'crew_snapshot', 'recorded_by', 'recorded_at', 'batch_code'])) {
                throw new BusinessRuleException('Historical production source, crew and actual times are immutable.');
            }
            if ($output->getOriginal('trial_inspection_id') !== null
                && $output->isDirty(['trial_inspection_id', 'trial_quantity', 'work_order_id'])) {
                throw new BusinessRuleException('Captured trial quantity and source are immutable.');
            }
        });
        static::deleting(function (self $output): void {
            if ($output->trial_inspection_id !== null || $output->production_started_at !== null) {
                throw new BusinessRuleException('Captured trial evidence cannot be deleted.');
            }
        });
    }

    public function trialInspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'trial_inspection_id');
    }

    public function getPhysicalCountAttribute(): int
    {
        return $this->trial_quantity ?? ($this->total_count + (int) $this->held_quantity);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** Every QC inspection raised against this output batch (outgoing). */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'work_order_output_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function defects(): HasMany
    {
        return $this->hasMany(WorkOrderDefect::class, 'output_id');
    }

    public function productionReceiptMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'production_receipt_movement_id');
    }

    public function getTotalCountAttribute(): int
    {
        return (int) $this->good_count + (int) $this->reject_count;
    }
}
