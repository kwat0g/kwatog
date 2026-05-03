<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sprint 8 — Task 70. */
class AssetDepreciation extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;
    protected $table = 'asset_depreciations';

    protected $fillable = [
        'asset_id',
        'period_year',
        'period_month',
        'depreciation_amount',
        'accumulated_after',
        'journal_entry_id',
        'created_at',
    ];

    protected $casts = [
        'period_year'         => 'integer',
        'period_month'        => 'integer',
        'depreciation_amount' => 'decimal:2',
        'accumulated_after'   => 'decimal:2',
        'created_at'          => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
