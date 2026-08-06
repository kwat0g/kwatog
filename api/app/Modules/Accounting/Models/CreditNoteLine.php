<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteLine extends Model
{
    use HasHashId;

    protected $fillable = ['credit_note_id', 'account_id', 'description', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function creditNote(): BelongsTo { return $this->belongsTo(CreditNote::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
}
