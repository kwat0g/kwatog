<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnReceipt extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'return_request_id',
        'request_key',
        'payload_fingerprint',
        'final_receipt',
        'quarantine_location_id',
        'received_by',
        'received_at',
    ];

    protected $casts = [
        'final_receipt' => 'boolean',
        'received_at' => 'datetime',
    ];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function quarantineLocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'quarantine_location_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnReceiptItem::class);
    }
}
