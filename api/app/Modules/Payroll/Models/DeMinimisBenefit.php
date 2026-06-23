<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\DeMinimisBenefitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeMinimisBenefit extends Model
{
    use HasHashId;

    protected $table = 'de_minimis_benefits';

    protected $fillable = [
        'employee_id',
        'benefit_type',
        'amount',
        'payroll_id',
        'period_year',
        'period_month',
        'is_taxable_portion',
        'notes',
    ];

    protected $casts = [
        'benefit_type'       => DeMinimisBenefitType::class,
        'amount'             => 'decimal:2',
        'is_taxable_portion' => 'boolean',
        'period_year'        => 'integer',
        'period_month'       => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class, 'payroll_id');
    }
}
