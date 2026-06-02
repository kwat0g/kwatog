<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLineItem extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'budget_id',
        'account_id',
        'jan', 'feb', 'mar', 'apr', 'may', 'jun',
        'jul', 'aug', 'sep', 'oct', 'nov', 'dec',
        'actual_total',
    ];

    protected $casts = [
        'jan' => 'decimal:2',
        'feb' => 'decimal:2',
        'mar' => 'decimal:2',
        'apr' => 'decimal:2',
        'may' => 'decimal:2',
        'jun' => 'decimal:2',
        'jul' => 'decimal:2',
        'aug' => 'decimal:2',
        'sep' => 'decimal:2',
        'oct' => 'decimal:2',
        'nov' => 'decimal:2',
        'dec' => 'decimal:2',
        'actual_total' => 'decimal:2',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** Monthly allocated amount for a given month index (1-12). */
    public function monthAmount(int $month): float
    {
        $col = match ($month) {
            1  => 'jan', 2  => 'feb', 3  => 'mar',
            4  => 'apr', 5  => 'may', 6  => 'jun',
            7  => 'jul', 8  => 'aug', 9  => 'sep',
            10 => 'oct', 11 => 'nov', 12 => 'dec',
        };
        return (float) ($this->{$col} ?? 0);
    }
}
