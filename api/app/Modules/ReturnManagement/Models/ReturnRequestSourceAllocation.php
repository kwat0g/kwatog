<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A locked reservation against one source document line.
 *
 * Source documents retain their ordered/invoiced/received quantities, so an
 * RMA needs its own ledger to prevent two open requests from consuming the
 * same line concurrently. `released_at` is set when a draft is canceled or
 * rejected; a supplier allocation is released after its receipt ledger is
 * decremented because the source row then becomes authoritative.
 */
class ReturnRequestSourceAllocation extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'return_request_item_id',
        'source_kind',
        'source_id',
        'quantity',
        'unit_price',
        'released_at',
    ];

    protected $casts = [
        'quantity'    => 'decimal:3',
        'unit_price'  => 'decimal:2',
        'released_at' => 'datetime',
    ];

    public function returnRequestItem(): BelongsTo
    {
        return $this->belongsTo(ReturnRequestItem::class);
    }
}
