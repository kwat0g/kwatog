<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\SuccessionPriority;
use App\Modules\HR\Enums\SuccessionReadiness;
use App\Modules\HR\Enums\SuccessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SuccessionPlan extends Model
{
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected $fillable = [
        'position_id',
        'incumbent_id',
        'successor_id',
        'readiness',
        'priority',
        'development_notes',
        'target_date',
        'created_by',
    ];

    protected $casts = [
        'readiness'   => SuccessionReadiness::class,
        'priority'    => SuccessionPriority::class,
        'status'      => SuccessionStatus::class,
        'target_date' => 'date',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function incumbent(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'incumbent_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'successor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
