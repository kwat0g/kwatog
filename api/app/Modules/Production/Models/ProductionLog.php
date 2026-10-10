<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Enums\ProductionLogEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionLog extends Model
{
    use HasAuditLog, HasHashId;

    public const UPDATED_AT = null;

    protected $fillable = [
        'wo_operation_id',
        'operator_id',
        'event_type',
        'qty_value',
        'downtime_reason',
        'notes',
        'recorded_at',
        'material_handover_exception',
    ];

    protected $casts = [
        'event_type' => ProductionLogEvent::class,
        'qty_value' => 'decimal:4',
        'recorded_at' => 'datetime',
        'material_handover_exception' => 'array',
    ];

    public function woOperation(): BelongsTo
    {
        return $this->belongsTo(WoOperation::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'operator_id');
    }
}
