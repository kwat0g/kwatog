<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmploymentHistory extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'employment_history';
    public $timestamps = false;
    protected $fillable = [
        'employee_id', 'change_type', 'from_value', 'to_value', 'effective_date', 'remarks', 'approved_by', 'created_at',
    ];
    protected $casts = [
        'from_value'     => 'array',
        'to_value'       => 'array',
        'effective_date' => 'date',
        'created_at'     => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'approved_by');
    }
}
