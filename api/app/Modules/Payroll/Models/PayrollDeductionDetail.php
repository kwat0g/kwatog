<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Enums\DeductionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollDeductionDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_id',
        'deduction_type',
        'description',
        'amount',
        'reference_id',
    ];

    protected $casts = [
        'deduction_type' => DeductionType::class,
        'amount'         => 'decimal:2',
    ];

    public $timestamps = false;

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }
}
