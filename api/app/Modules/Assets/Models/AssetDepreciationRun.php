<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDepreciationRun extends Model
{
    protected $table = 'asset_depreciation_runs';

    protected $fillable = [
        'period_year',
        'period_month',
        'journal_entry_id',
        'posted_count',
        'total_amount',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'journal_entry_id' => 'integer',
        'posted_count' => 'integer',
        'total_amount' => 'decimal:2',
    ];
}
