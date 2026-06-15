<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;

class CopqSnapshot extends Model
{
    use HasHashId;

    protected $table = 'copq_snapshots';

    protected $fillable = [
        'period_year', 'period_month',
        'prevention_cost', 'appraisal_cost',
        'internal_scrap_cost', 'internal_rework_cost',
        'external_return_cost', 'external_complaint_cost',
        'total_cost', 'breakdown', 'computed_at',
    ];

    protected $casts = [
        'period_year'              => 'integer',
        'period_month'             => 'integer',
        'prevention_cost'          => 'decimal:2',
        'appraisal_cost'           => 'decimal:2',
        'internal_scrap_cost'      => 'decimal:2',
        'internal_rework_cost'     => 'decimal:2',
        'external_return_cost'     => 'decimal:2',
        'external_complaint_cost'  => 'decimal:2',
        'total_cost'               => 'decimal:2',
        'breakdown'                => 'array',
        'computed_at'              => 'datetime',
    ];

    public function periodLabel(): string
    {
        return sprintf('%04d-%02d', $this->period_year, $this->period_month);
    }
}
