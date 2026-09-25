<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryAttemptOutcomeItem extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'delivery_attempt_outcome_id', 'delivery_item_id', 'shipped_quantity',
        'customer_received_quantity', 'customer_received_damaged_quantity',
        'truck_return_quantity', 'truck_return_damaged_quantity', 'declared_unaccounted_quantity',
        'warehouse_received_quantity', 'unaccounted_quantity',
    ];

    protected $casts = [
        'shipped_quantity' => 'decimal:3',
        'customer_received_quantity' => 'decimal:3',
        'customer_received_damaged_quantity' => 'decimal:3',
        'truck_return_quantity' => 'decimal:3',
        'truck_return_damaged_quantity' => 'decimal:3',
        'declared_unaccounted_quantity' => 'decimal:3',
        'warehouse_received_quantity' => 'decimal:3',
        'unaccounted_quantity' => 'decimal:3',
    ];

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttemptOutcome::class, 'delivery_attempt_outcome_id');
    }

    public function deliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(DeliveryAttemptOutcomeMovement::class)->orderBy('id');
    }
}
