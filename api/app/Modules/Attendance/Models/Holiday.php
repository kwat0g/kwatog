<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Attendance\Enums\HolidayType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = ['name', 'date', 'type', 'is_recurring'];

    protected $casts = [
        'date'         => 'date',
        'type'         => HolidayType::class,
        'is_recurring' => 'boolean',
    ];
}
