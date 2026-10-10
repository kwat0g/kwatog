<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderCrewMember extends Model
{
    public $timestamps = false;

    protected $fillable = ['employee_id', 'employee_snapshot'];

    protected $casts = ['employee_snapshot' => 'array'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
