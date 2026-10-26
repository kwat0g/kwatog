<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Factories\WorkOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use HasAuditLog, HasFactory, HasHashId, SoftDeletes;

    protected static function newFactory(): Factory
    {
        return WorkOrderFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $workOrder): void {
            if ($workOrder->getAttribute('qc_workflow') !== null) {
                throw new BusinessRuleException('QC workflow is selected by production policy and cannot be supplied by a caller.');
            }
            if (config('inspection.finish_first_wo_enabled')
                && $workOrder->isFinishFirstQcEligible()) {
                $workOrder->setAttribute('qc_workflow', 'finish_first_v1');
            }
        });
        static::updating(function (self $workOrder): void {
            if ($workOrder->getOriginal('production_purpose') === 'stock' && $workOrder->isDirty(['production_purpose', 'sales_order_id', 'sales_order_item_id'])) {
                throw new BusinessRuleException('Stock production purpose and source ownership are immutable. Allocate stock to an order instead.');
            }
            if ($workOrder->isDirty('qc_workflow')) {
                throw new BusinessRuleException('The QC workflow is immutable after work order creation.');
            }
        });
    }

    protected $fillable = [
        'production_purpose', 'stock_creation_request_key', 'stock_creation_fingerprint',
        'short_completion_reason',
        'wo_number', 'product_id', 'sales_order_id', 'sales_order_item_id',
        'mrp_plan_id', 'parent_wo_id', 'parent_ncr_id', 'machine_id', 'mold_id', 'rework_source_output_id',
        'quantity_target', 'quantity_produced', 'quantity_good',
        'quantity_rejected', 'scrap_rate',
        'planned_start', 'planned_end', 'preferred_production_date', 'actual_start', 'actual_end',
        'status', 'pause_reason', 'priority', 'created_by',
        'work_order_class', 'exception_reason', 'exception_authorized_by', 'material_plan_source',
        // ADV3 — IATF 16949 traceability.
        'batch_number', 'material_lot_references',
        'qc_workflow',
        'bom_id', 'bom_version', 'unit_standard_cost', 'cost_source', 'cost_snapshot_at',
        'minimum_workers', 'maximum_workers', 'production_released_at', 'scheduling_diagnostic',
    ];

    protected $casts = [
        'status' => WorkOrderStatus::class,
        'bom_id' => 'integer', 'bom_version' => 'integer',
        'unit_standard_cost' => 'decimal:4', 'cost_snapshot_at' => 'datetime',
        'planned_start' => 'datetime',
        'planned_end' => 'datetime',
        'preferred_production_date' => 'date',
        'actual_start' => 'datetime', 'production_released_at' => 'datetime',
        'actual_end' => 'datetime',
        'quantity_target' => 'decimal:0',
        'quantity_produced' => 'decimal:0',
        'quantity_good' => 'decimal:0',
        'quantity_rejected' => 'decimal:0',
        'scrap_rate' => 'decimal:2',
        'priority' => 'integer',
        'scheduling_diagnostic' => 'array',
        'minimum_workers' => 'integer', 'maximum_workers' => 'integer',
        'exception_authorized_by' => 'integer',
        // ADV3 — array of {item_id, item_code, item_name, grn_number, material_lot_number, supplier_lot_reference, quantity_used}.
        'material_lot_references' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    /**
     * A root work order bound to an order line. MRP counts its good output
     * against that line and re-plans any shortfall — including a batch that
     * failed outgoing QC — so it is the one owner of replacement production.
     */
    public function coversSalesOrderLine(): bool
    {
        return $this->sales_order_item_id !== null && $this->parent_wo_id === null;
    }

    public function usesFinishFirstQc(): bool
    {
        $workflow = $this->getAttribute('qc_workflow');
        if ($workflow === null) {
            return false;
        }
        if ($workflow !== 'finish_first_v1') {
            throw new BusinessRuleException("Work order {$this->wo_number} has an unsupported QC workflow. Reconcile the deployment before continuing.");
        }

        return true;
    }

    public function assertFinishFirstRoutingCompatible(): void
    {
        if (! $this->usesFinishFirstQc()) {
            return;
        }

        $requiresInProcessQc = ProductRouting::query()
            ->where('product_id', $this->product_id)
            ->where('is_active', true)
            ->whereHas('operations', fn (Builder $query) => $query->where('qc_required', true))
            ->exists();
        if ($requiresInProcessQc) {
            throw new BusinessRuleException('This routing requires QC during production. Resolve the routing before confirming or starting this work order.');
        }
    }

    private function isFinishFirstQcEligible(): bool
    {
        if (($this->work_order_class ?: 'standard') !== 'standard'
            || $this->parent_wo_id !== null || $this->rework_source_output_id !== null) {
            return false;
        }

        return ! ProductRouting::query()
            ->where('product_id', $this->product_id)
            ->where('is_active', true)
            ->whereHas('operations', fn (Builder $query) => $query->where('qc_required', true))
            ->exists();
    }

    /** An unresolved process failure blocks further production and release. */
    public function assertProcessQualityReleased(?int $excludedOperationId = null): void
    {
        $finishFirst = $this->usesFinishFirstQc();
        $this->assertFinishFirstRoutingCompatible();
        $inspections = $this->inspections()
            ->where('stage', InspectionStage::InProcess->value)
            ->when($excludedOperationId !== null, fn ($query) => $query->where(fn ($query) => $query->whereNull('wo_operation_id')->orWhere('wo_operation_id', '!=', $excludedOperationId)))
            ->with(['ncr', 'resultAuthors', 'workOrderOutput'])
            ->get();
        $activeInspections = $inspections->reject(
            static fn (Inspection $inspection): bool => $inspection->status === InspectionStatus::Cancelled,
        );
        $activeWorkOrderInspections = $activeInspections->whereNull('wo_operation_id');

        if ($activeWorkOrderInspections->isEmpty() && ! $finishFirst) {
            throw new BusinessRuleException(
                "WO {$this->wo_number} has no active in-process QC inspection. Create or replay the QC inspection before continuing."
            );
        }

        foreach ($activeInspections as $inspection) {
            $hasAuthorizedResult = $inspection->isMakerChecked()
                || (! $inspection->requiresMakerChecker() && $inspection->resultAuthors->isNotEmpty());
            if (! in_array($inspection->status, [InspectionStatus::Passed, InspectionStatus::Failed], true)
                || ! $hasAuthorizedResult) {
                throw new BusinessRuleException(
                    "WO {$this->wo_number} is waiting for in-process QC {$inspection->inspection_number} to receive an approved final result."
                );
            }
            $trialOutput = $inspection->startup_trial_required ? $inspection->trialOutput()->first() : null;
            if ($inspection->startup_trial_required && (! $trialOutput || ! $trialOutput->trial_settled_at)) {
                throw new BusinessRuleException("WO {$this->wo_number} is waiting for startup trial disposition before continuing.");
            }
            if ($inspection->status === InspectionStatus::Passed) {
                continue;
            }

            $ncr = $inspection->ncr;
            if (! $ncr || $ncr->status->value === 'cancelled' || ! $ncr->sorting_approved_at || ! $ncr->sorting_evidence_reference
                || (int) $ncr->sorting_inspected_quantity !== $inspection->processRecoveryQuantity()
                || (int) $ncr->sorting_approved_by === (int) $ncr->sorting_performed_by
                || (int) $ncr->sorting_approved_by === (int) $inspection->inspector_id
                || $inspection->hasResultAuthorId((int) $ncr->sorting_approved_by)
                || ($inspection->startup_trial_required
                    ? (int) $ncr->mrb_accepted_quantity !== (int) $trialOutput->good_count
                        || (int) $trialOutput->good_count + (int) $trialOutput->reject_count !== $inspection->processRecoveryQuantity()
                    : ($inspection->work_order_output_id && $inspection->workOrderOutput?->production_started_at
                        ? (int) $inspection->workOrderOutput->held_quantity !== 0 || (int) $inspection->workOrderOutput->total_count !== $inspection->processRecoveryQuantity()
                        : (int) $ncr->mrb_accepted_quantity !== $inspection->processRecoveryQuantity()))) {
                throw new BusinessRuleException("WO {$this->wo_number} is held by failed in-process QC {$inspection->inspection_number}. Approve recovery before continuing.");
            }
        }
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function mold(): BelongsTo
    {
        return $this->belongsTo(Mold::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_wo_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_wo_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exceptionAuthorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exception_authorized_by');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(WorkOrderOutput::class)->orderByDesc('recorded_at');
    }

    public function crews(): HasMany
    {
        return $this->hasMany(WorkOrderCrew::class)->orderByDesc('id');
    }

    public function activeReworks(): HasMany
    {
        return $this->hasMany(NonConformanceReport::class, 'rework_work_order_id')
            ->whereNotIn('status', ['closed', 'cancelled'])->where('disposition', 'rework')
            ->whereNotNull('sorting_approved_at')->where('confirmed_rework_quantity', '>', 0)
            ->whereHas('inspection', fn ($query) => $query->where('entity_type', 'work_order')
                ->whereColumn('inspections.entity_id', 'non_conformance_reports.rework_work_order_id'));
    }

    /** Quality inspections whose polymorphic production entity is this work order. */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'entity_id')
            ->where('entity_type', 'work_order')
            ->orderByDesc('id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(WoOperation::class)->orderBy('sequence');
    }

    public function downtimes(): HasMany
    {
        return $this->hasMany(MachineDowntime::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ProductionSchedule::class);
    }

    public function currentProductionSchedule(): HasOne
    {
        return $this->hasOne(ProductionSchedule::class)->ofMany(['id' => 'max'], function (Builder $query): void {
            $query->whereNull('rework_ncr_id')->where('reserves_capacity', true)
                ->whereIn('status', ['pending', 'confirmed', 'executed']);
        });
    }

    public function scopeStatus(Builder $q, WorkOrderStatus|string $s): Builder
    {
        return $q->where('status', $s instanceof WorkOrderStatus ? $s->value : $s);
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->whereIn('status', [WorkOrderStatus::Confirmed->value, WorkOrderStatus::InProgress->value])
            ->whereNotNull('planned_end')
            ->where('planned_end', '<', now())
            ->whereNull('work_orders.deleted_at');
    }

    public function getProgressPercentageAttribute(): float
    {
        $target = (int) $this->quantity_target;
        if ($target <= 0) {
            return 0.0;
        }

        return round(min(100.0, ((int) $this->quantity_produced / $target) * 100), 1);
    }
}
