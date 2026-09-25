<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\SupplyChain\Enums\DeliveryAttemptRevisionKind;
use Illuminate\Database\Eloquent\Model;

/** Append-only correction/recovery history; aggregate quantities remain projections. */
class DeliveryAttemptRevision extends Model
{
    use HasHashId;

    protected $guarded = ['id'];
    protected $casts = [
        'kind' => DeliveryAttemptRevisionKind::class,
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
    ];

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'created_by');
    }
}
