<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Models\Machine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADV8 — Maintenance Automation.
 * A single sensor-like or manual reading for a machine health metric.
 */
class MachineConditionReading extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'machine_condition_readings';

    protected $fillable = [
        'machine_id',
        'metric',
        'value',
        'unit',
        'recorded_at',
        'source',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'value'       => 'decimal:3',
        'recorded_at' => 'datetime',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
