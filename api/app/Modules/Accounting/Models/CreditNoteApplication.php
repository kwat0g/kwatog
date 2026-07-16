<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteApplication extends Model
{
    use HasHashId;

    protected $fillable = ['credit_note_id', 'invoice_id', 'bill_id', 'amount', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function creditNote(): BelongsTo { return $this->belongsTo(CreditNote::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function bill(): BelongsTo { return $this->belongsTo(Bill::class); }
}
