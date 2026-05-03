<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;

    protected $fillable = [
        'journal_entry_id', 'account_id', 'line_no',
        'debit', 'credit', 'description',
    ];

    protected $casts = [
        'debit'   => 'decimal:2',
        'credit'  => 'decimal:2',
        'line_no' => 'integer',
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
