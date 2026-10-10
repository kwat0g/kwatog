<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\InvoicePaymentSubmissionStatus;
use App\Modules\B2B\Models\CustomerPortalUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePaymentSubmission extends Model
{
    use HasHashId, HasAuditLog;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2', 'balance_at_submission' => 'decimal:2',
        'payment_date' => 'date', 'reviewed_at' => 'datetime',
        'status' => InvoicePaymentSubmissionStatus::class,
    ];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function collection(): BelongsTo { return $this->belongsTo(Collection::class); }
    public function submitter(): BelongsTo { return $this->belongsTo(CustomerPortalUser::class, 'submitted_by'); }
}
