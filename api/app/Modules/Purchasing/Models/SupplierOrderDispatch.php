<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Purchasing\Enums\SupplierDispatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOrderDispatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'idempotency_key',
        'channel',
        'status',
        'attempts',
        'recipient_count',
        'queued_at',
        'last_attempt_at',
        'published_at',
        'confirmed_at',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierDispatchStatus::class,
            'attempts' => 'integer',
            'recipient_count' => 'integer',
            'queued_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'published_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
