<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\CommissionStatus;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionEarning extends Model
{
    use SoftDeletes, HasHashId, HasAuditLog;

    protected $fillable = [
        'sales_order_id',
        'employee_id',
        'order_total',
        'commission_rate',
        'commission_amount',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'order_total'       => 'decimal:2',
        'commission_rate'   => 'decimal:4',
        'commission_amount' => 'decimal:2',
        'status'            => CommissionStatus::class,
        'approved_at'       => 'datetime',
        'paid_at'           => 'datetime',
        'period_start'      => 'date',
        'period_end'        => 'date',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
