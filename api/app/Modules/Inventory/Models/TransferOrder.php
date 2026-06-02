<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferOrder extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'transfer_number', 'from_location_id', 'to_location_id', 'item_id',
        'quantity', 'reason', 'status', 'created_by', 'transferred_by', 'transferred_at',
    ];

    protected $casts = [
        'quantity'       => 'decimal:3',
        'transferred_at' => 'datetime',
    ];

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'to_location_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', User::class), 'created_by');
    }

    public function transferrer(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model', User::class), 'transferred_by');
    }
}
