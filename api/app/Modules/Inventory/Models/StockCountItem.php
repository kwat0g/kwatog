<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'session_id', 'location_id', 'item_id',
        'system_quantity', 'counted_quantity', 'variance', 'variance_percent',
        'lot_number', 'status', 'counted_by', 'counted_at', 'notes',
    ];

    protected $casts = [
        'system_quantity'   => 'decimal:3',
        'counted_quantity'  => 'decimal:3',
        'variance'          => 'decimal:3',
        'variance_percent'  => 'decimal:2',
        'counted_at'        => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StockCountSession::class, 'session_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', User::class), 'counted_by');
    }
}
