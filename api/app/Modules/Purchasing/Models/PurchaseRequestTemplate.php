<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'department_id',
        'items',
        'notes',
        'created_by',
        'is_active',
    ];

    protected $casts = [
        'items'     => 'array',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
