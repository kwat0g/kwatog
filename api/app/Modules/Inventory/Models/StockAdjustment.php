<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OGAMI-012 — manual stock-adjustment record.
 *
 * Captures the adjustment intent, structured reason code, and approval state.
 * The actual ledger mutation lives on the linked `stock_movement` (posted
 * immediately for sub-threshold adjustments, or after approve() otherwise).
 *
 * `status` is intentionally NOT mass-assignable (WIP hardening convention):
 * the service writes it via fill()/forceFill + save().
 */
class StockAdjustment extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'stock_adjustments';

    protected $fillable = [
        'item_id', 'location_id', 'direction', 'quantity', 'unit_cost',
        'value', 'reason_code', 'reason', 'stock_movement_id',
        'requested_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'unit_cost'   => 'decimal:4',
        'value'       => 'decimal:2',
        'reason_code' => StockAdjustmentReason::class,
        'approved_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
