<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REC-08 — Material Review Board record.
 *
 * Ties a nonconforming lot (optionally linked to an NCR / inspection) to the
 * physical quarantine stock movement, its disposition, and the release
 * movement. The `status` lifecycle field is excluded from `$fillable` and
 * written via property-set inside {@see \App\Modules\Inventory\Services\QuarantineService}.
 */
class MaterialReviewRecord extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $table = 'material_review_records';

    protected $fillable = [
        'mrb_number', 'ncr_id', 'inspection_id', 'item_id', 'quantity',
        'source_location_id', 'quarantine_location_id',
        'disposition', 'hold_movement_id', 'release_movement_id',
        'held_by', 'held_at', 'released_by', 'released_at',
        'release_location_id', 'notes',
        // 'status' intentionally excluded — lifecycle written via property-set.
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'status'      => MrbStatus::class,
        'held_at'     => 'datetime',
        'released_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'ncr_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class, 'inspection_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'source_location_id');
    }

    public function quarantineLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'quarantine_location_id');
    }

    public function releaseLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'release_location_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function holdMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'hold_movement_id');
    }

    public function releaseMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'release_movement_id');
    }
}
