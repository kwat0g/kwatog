<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use Database\Factories\SalesOrderStockAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderStockAllocation extends Model
{
    use HasFactory, HasHashId;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:3', 'transferred_quantity' => 'decimal:3', 'consumed_quantity' => 'decimal:3', 'released_quantity' => 'decimal:3', 'expiry_date' => 'date'];

    protected static function newFactory(): SalesOrderStockAllocationFactory
    {
        return SalesOrderStockAllocationFactory::new();
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOutput::class, 'work_order_output_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function untransferredQuantity(): string
    {
        return bcsub(bcsub((string) $this->quantity, (string) $this->transferred_quantity, 3), (string) $this->released_quantity, 3);
    }
}
