<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryAttemptOutcomeMovement extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'delivery_attempt_outcome_item_id', 'stock_movement_id', 'declared_quantity', 'received_quantity',
    ];

    protected $casts = [
        'declared_quantity' => 'decimal:3',
        'received_quantity' => 'decimal:3',
        'customer_received_quantity' => 'decimal:3',
    ];

    public function outcomeItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttemptOutcomeItem::class, 'delivery_attempt_outcome_item_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function returnRequestItems(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class, 'delivery_attempt_outcome_movement_id');
    }
}
