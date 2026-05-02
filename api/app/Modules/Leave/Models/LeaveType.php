<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'name', 'code', 'default_balance',
        'is_paid', 'requires_document',
        'is_convertible_on_separation', 'is_convertible_year_end',
        'conversion_rate', 'is_active',
    ];

    protected $casts = [
        'default_balance'              => 'decimal:1',
        'is_paid'                      => 'boolean',
        'requires_document'            => 'boolean',
        'is_convertible_on_separation' => 'boolean',
        'is_convertible_year_end'      => 'boolean',
        'conversion_rate'              => 'decimal:2',
        'is_active'                    => 'boolean',
    ];

    public function balances(): HasMany
    {
        return $this->hasMany(EmployeeLeaveBalance::class);
    }
}
