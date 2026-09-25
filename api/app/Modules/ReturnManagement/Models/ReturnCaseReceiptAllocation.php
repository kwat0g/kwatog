<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accepted receipt quantities committed to a specific case line. */
class ReturnCaseReceiptAllocation extends Model
{
    use HasHashId;

    protected $guarded = ['*'];

    protected $casts = ['quantity' => 'decimal:3'];

    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class);
    }

    public function caseLine(): BelongsTo
    {
        return $this->belongsTo(ReturnCaseLine::class, 'return_case_line_id');
    }
}
