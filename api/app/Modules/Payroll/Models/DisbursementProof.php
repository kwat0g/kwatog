<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADV1 — Each row is one proof file (deposit slip, bank confirmation, etc.)
 * attached to a payroll period after the bank file has been generated.
 */
class DisbursementProof extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    // Migration 0151 created this as `payroll_disbursement_proofs`; without an
    // explicit table the model would default to `disbursement_proofs` (which
    // does not exist) and every query would fail at runtime.
    protected $table = 'payroll_disbursement_proofs';

    protected $fillable = [
        'payroll_period_id',
        'proof_type',
        'file_name',
        'file_path',
        'bank_name',
        'transaction_reference',
        'disbursed_amount',
        'disbursement_date',
        'uploaded_by',
        'notes',
    ];

    protected $casts = [
        'disbursed_amount'  => 'decimal:2',
        'disbursement_date' => 'date',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
