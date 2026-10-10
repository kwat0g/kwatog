<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Enums\ItemCostReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemCostReview extends Model
{
    use HasAuditLog, HasHashId;

    protected $guarded = ['id'];

    protected $casts = ['status' => ItemCostReviewStatus::class, 'previous_cost' => 'decimal:4', 'proposed_cost' => 'decimal:4', 'reviewed_at' => 'datetime', 'version' => 'integer', 'history' => 'array'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class, 'grn_item_id');
    }
}
