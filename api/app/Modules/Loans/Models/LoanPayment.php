<?php

declare(strict_types=1);

namespace App\Modules\Loans\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanPayment extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;
    protected $fillable = ['loan_id', 'payroll_id', 'amount', 'payment_date', 'payment_type', 'remarks', 'created_at'];
    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
        'created_at'   => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'loan_id');
    }
}
