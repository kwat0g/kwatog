<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Position extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'title',
        'department_id',
        'salary_grade',
        'default_role_id',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function defaultRole(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\Role::class, 'default_role_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
