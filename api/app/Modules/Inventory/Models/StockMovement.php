<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockMovement extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    public $timestamps = false;

    protected $fillable = [
        'item_id', 'from_location_id', 'to_location_id',
        'movement_type', 'quantity', 'unit_cost', 'total_cost',
        'reference_type', 'reference_id', 'remarks', 'created_by', 'created_at',
        'journal_entry_id', 'gl_handoff_status', 'gl_handoff_message', 'gl_handoff_at',
        // OGAMI-012 — lot/batch traceability (null-safe; optional).
        'lot_number', 'expiry_date',
        'idempotency_key', 'idempotency_fingerprint',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'movement_type' => StockMovementType::class,
        'gl_handoff_status' => MovementGlHandoffStatus::class,
        'reference_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_at' => 'datetime',
        'gl_handoff_at' => 'datetime',
        'expiry_date' => 'date',
    ];

    public function referenceMaintenanceWorkOrder(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Maintenance\Models\MaintenanceWorkOrder::class, 'reference_id');
    }

    public function referenceWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'reference_id');
    }

    public function handover(): HasOne
    {
        return $this->hasOne(MaterialHandover::class);
    }

    public function referenceOutput(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOutput::class, 'reference_id');
    }

    public function referenceSlip(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueSlip::class, 'reference_id');
    }

    public function referenceSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_id');
    }

    public function referenceGoodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class, 'reference_id');
    }

    public function referenceDeliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class, 'reference_id');
    }

    public const HANDOVER_RETURNED_SQL = "(SELECT COALESCE(SUM(returned.quantity), 0) FROM stock_movements AS returned WHERE returned.movement_type = 'material_return' AND returned.reference_type = 'stock_movement' AND returned.reference_id = stock_movements.id)";

    public function scopeHandoverSources(Builder $query): void
    {
        $query->where('movement_type', StockMovementType::MaterialIssue->value)
            ->whereNotNull('from_location_id')->whereNull('to_location_id')
            ->where(fn ($q) => $q
                ->where(fn ($direct) => $direct->whereIn('reference_type', ['work_order', 'WorkOrder'])
                    ->whereHas('referenceWorkOrder.materials', fn ($material) => $material->whereColumn('work_order_materials.item_id', 'stock_movements.item_id')))
                ->orWhere(fn ($slip) => $slip->where('reference_type', 'material_issue_slip')
                    ->whereHas('referenceSlip.items', fn ($item) => $item->whereColumn('material_issue_slip_items.stock_movement_id', 'stock_movements.id')
                        ->whereColumn('material_issue_slip_items.item_id', 'stock_movements.item_id'))
                    ->whereHas('referenceSlip', fn ($record) => $record->whereNull('work_order_id')->orWhereHas('workOrder')))
                ->orWhere(fn ($maintenance) => $maintenance->where('reference_type', 'maintenance_work_order')->whereHas('referenceMaintenanceWorkOrder')));
    }

    public function scopeWithHandoverBalance(Builder $query): void
    {
        $query->addSelect('stock_movements.*')->selectRaw(self::HANDOVER_RETURNED_SQL.' AS returned_quantity')
            ->selectRaw('GREATEST(stock_movements.quantity - '.self::HANDOVER_RETURNED_SQL.', 0) AS remaining_quantity');
    }

    public function scopePendingHandover(Builder $query): void
    {
        $query->handoverSources()->whereDoesntHave('handover')
            ->whereRaw('stock_movements.quantity > '.self::HANDOVER_RETURNED_SQL);
    }

    public function scopeForWorkOrder(Builder $query, int $woId): void
    {
        $query->where(fn ($q) => $q
            ->where(fn ($direct) => $direct->whereIn('reference_type', ['work_order', 'WorkOrder'])->where('reference_id', $woId))
            ->orWhere(fn ($output) => $output->whereIn('reference_type', ['work_order_output', 'WorkOrderOutput'])
                ->whereHas('referenceOutput', fn ($record) => $record->where('work_order_id', $woId)))
            ->orWhere(fn ($slip) => $slip->where('reference_type', 'material_issue_slip')
                ->whereHas('referenceSlip', fn ($record) => $record->where('work_order_id', $woId)))
            ->orWhere(fn ($return) => $return->where('reference_type', 'stock_movement')
                ->where('movement_type', StockMovementType::MaterialReturn->value)
                ->whereHas('referenceSource', fn ($source) => $source->where(fn ($issue) => $issue
                    ->where(fn ($direct) => $direct->whereIn('reference_type', ['work_order', 'WorkOrder'])->where('reference_id', $woId))
                    ->orWhere(fn ($slip) => $slip->where('reference_type', 'material_issue_slip')
                        ->whereHas('referenceSlip', fn ($record) => $record->where('work_order_id', $woId)))))));
    }

    /** Resolve output references to their parent WO without exposing internal IDs. */
    public function workOrderReference(): array
    {
        $wo = match ($this->reference_type) {
            'work_order', 'WorkOrder' => $this->referenceWorkOrder,
            'work_order_output', 'WorkOrderOutput' => $this->referenceOutput?->workOrder,
            'material_issue_slip' => $this->referenceSlip?->workOrder,
            'stock_movement' => $this->movement_type === StockMovementType::MaterialReturn
                ? match ($this->referenceSource?->reference_type) {
                    'work_order', 'WorkOrder' => $this->referenceSource->referenceWorkOrder,
                    'material_issue_slip' => $this->referenceSource->referenceSlip?->workOrder,
                    default => null,
                } : null,
            default => null,
        };

        $slip = $this->reference_type === 'material_issue_slip' ? $this->referenceSlip
            : ($this->reference_type === 'stock_movement' && $this->movement_type === StockMovementType::MaterialReturn
                && $this->referenceSource?->reference_type === 'material_issue_slip' ? $this->referenceSource->referenceSlip : null);
        $grn = in_array($this->reference_type, ['goods_receipt_note', 'GoodsReceiptNote'], true)
            ? $this->referenceGoodsReceiptNote : null;
        $delivery = $this->reference_type === 'delivery_item'
            ? $this->referenceDeliveryItem?->delivery : null;

        return [
            'reference_label' => ($this->reference_type === 'material_issue_slip' ? $this->referenceSlip?->slip_number : null)
                ?? ($this->reference_type === 'stock_movement' && $wo ? 'Unused return · '.$wo->wo_number : null)
                ?? ($slip ? 'Unused return · '.$slip->slip_number : null)
                ?? $wo?->wo_number ?? $grn?->grn_number ?? $delivery?->delivery_number
                ?? (in_array($this->reference_type, ['work_order', 'WorkOrder', 'work_order_output', 'WorkOrderOutput'], true)
                ? 'Work order unavailable' : null),
            'reference_work_order_id' => $wo?->hash_id,
            'reference_url' => match (true) {
                $slip !== null => '/inventory/material-issues/'.$slip->hash_id,
                $wo !== null => '/production/work-orders/'.$wo->hash_id,
                $grn !== null => '/inventory/grn/'.$grn->hash_id,
                $delivery !== null => '/supply-chain/deliveries/'.$delivery->hash_id,
                default => null,
            },
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'to_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
