<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRate extends Model
{
    use HasHashId;

    protected $fillable = [
        'employee_id',
        'product_id',
        'rate',
        'effective_from',
        'effective_until',
    ];

    protected $casts = [
        'rate'            => 'decimal:4',
        'effective_from'  => 'date',
        'effective_until' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActiveOn(Builder $q, string $date): Builder
    {
        return $q->where('effective_from', '<=', $date)
            ->where(fn (Builder $b) => $b->whereNull('effective_until')->orWhere('effective_until', '>=', $date));
    }
}
