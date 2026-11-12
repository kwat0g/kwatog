<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderAdvanceApplication extends Model
{
    use HasAuditLog, HasHashId;

    protected $fillable = ['sales_order_advance_id', 'invoice_id', 'amount', 'journal_entry_id', 'applied_by', 'applied_at'];

    protected $casts = ['amount' => 'decimal:2', 'applied_at' => 'datetime'];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SalesOrderAdvance::class, 'sales_order_advance_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
