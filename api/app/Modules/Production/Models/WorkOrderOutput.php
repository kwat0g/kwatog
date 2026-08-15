<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use App\Common\Traits\HasAuditLog;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderOutput extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    public $timestamps = false;

    protected $fillable = [
        'work_order_id', 'recorded_by', 'recorded_at',
        'good_count', 'reject_count', 'shift', 'batch_code', 'remarks',
        'idempotency_key', 'idempotency_fingerprint',
        'production_receipt_handoff_status', 'production_receipt_handoff_message',
        'production_receipt_handoff_at', 'production_receipt_movement_id',
        'material_lineage',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'good_count' => 'integer',
        'reject_count' => 'integer',
        'production_receipt_handoff_status' => ProductionReceiptHandoffStatus::class,
        'production_receipt_handoff_at' => 'datetime',
        'production_receipt_movement_id' => 'integer',
        'material_lineage' => 'array',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
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
