<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Enums\SeparationReason;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Sprint 8 — Task 71. */
class Clearance extends Model
{
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected $table = 'clearances';

    protected $fillable = [
        'clearance_no',
        'employee_id',
        'separation_date',
        'separation_reason',
        'clearance_items',
        'final_pay_computed',
        'final_pay_amount',
        'final_pay_breakdown',
        'journal_entry_id',
        'status',
        'initiated_by',
        'finalized_at',
        'finalized_by',
        'remarks',
    ];

    protected $casts = [
        'separation_date'    => 'date',
        'separation_reason'  => SeparationReason::class,
        'clearance_items'    => 'array',
        'final_pay_breakdown'=> 'array',
        'final_pay_computed' => 'boolean',
        'final_pay_amount'   => 'decimal:2',
        'status'             => ClearanceStatus::class,
        'finalized_at'       => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
