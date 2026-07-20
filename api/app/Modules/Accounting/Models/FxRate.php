<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REC-12 — FX rate for statement translation.
 *
 * rate_to_functional = how many PHP (the functional currency) one unit of
 * `currency_code` is worth on `rate_date`.
 */
class FxRate extends Model
{
    use HasHashId;

    protected $fillable = [
        'currency_code', 'rate_date', 'rate_to_functional', 'source', 'created_by',
    ];

    protected $casts = [
        'rate_date'          => 'date',
        'rate_to_functional' => 'decimal:8',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
