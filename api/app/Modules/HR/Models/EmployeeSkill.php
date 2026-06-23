<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeSkillLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSkill extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'employee_id',
        'skill_id',
        'proficiency_level',
        'acquired_date',
        'expires_at',
        'certified_by',
        'certification_document_path',
        'notes',
    ];

    protected $casts = [
        'proficiency_level' => EmployeeSkillLevel::class,
        'acquired_date'     => 'date',
        'expires_at'        => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }
}
