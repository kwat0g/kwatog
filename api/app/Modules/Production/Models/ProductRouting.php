<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use App\Modules\CRM\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductRouting extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'product_id',
        'version',
        'is_active',
        'total_cycle_time',
        'notes',
    ];

    protected $casts = [
        'total_cycle_time' => 'decimal:2',
        'is_active'        => 'boolean',
        'version'          => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(RoutingOperation::class, 'routing_id')->orderBy('sequence');
    }
}
