<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Training extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'name', 'description', 'duration_hours', 'validity_months',
        'is_certification', 'department_id', 'is_active',
    ];

    protected $casts = [
        'duration_hours'    => 'decimal:2',
        'validity_months'   => 'integer',
        'is_certification'  => 'boolean',
        'is_active'         => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(EmployeeTraining::class);
    }
}
