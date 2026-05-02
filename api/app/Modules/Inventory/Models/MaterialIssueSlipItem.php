<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialIssueSlipItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_issue_slip_id', 'item_id', 'location_id',
        'quantity_issued', 'unit_cost', 'total_cost',
        'material_reservation_id', 'remarks',
    ];

    protected $casts = [
        'quantity_issued' => 'decimal:3',
        'unit_cost'       => 'decimal:4',
        'total_cost'      => 'decimal:2',
    ];

    public function slip(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueSlip::class, 'material_issue_slip_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }
}
