<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCountSession extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'session_number', 'title', 'scope', 'warehouse_id', 'zone_id',
        'status', 'total_locations', 'counted_locations', 'variance_count', 'variance_value',
        'created_by', 'approved_by', 'frozen_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'frozen_at'      => 'datetime',
        'completed_at'   => 'datetime',
        'total_locations' => 'integer',
        'counted_locations' => 'integer',
        'variance_count'  => 'integer',
        'variance_value'  => 'decimal:2',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', User::class), 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', User::class), 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class, 'session_id');
    }
}
