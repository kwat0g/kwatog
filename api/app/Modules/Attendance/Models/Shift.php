<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'name', 'start_time', 'end_time', 'break_minutes',
        'is_night_shift', 'is_extended', 'auto_ot_hours', 'is_active',
    ];

    protected $casts = [
        'is_night_shift' => 'boolean',
        'is_extended'    => 'boolean',
        'is_active'      => 'boolean',
        'auto_ot_hours'  => 'decimal:1',
        'break_minutes'  => 'integer',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }
}
