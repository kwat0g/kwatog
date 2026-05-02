<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Payroll\Enums\ContributionAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GovernmentContributionTable extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $table = 'government_contribution_tables';

    protected $fillable = [
        'agency',
        'bracket_min',
        'bracket_max',
        'ee_amount',
        'er_amount',
        'effective_date',
        'is_active',
    ];

    protected $casts = [
        'agency'         => ContributionAgency::class,
        'bracket_min'    => 'decimal:2',
        'bracket_max'    => 'decimal:2',
        'ee_amount'      => 'decimal:4',
        'er_amount'      => 'decimal:4',
        'effective_date' => 'date',
        'is_active'      => 'boolean',
    ];

    // ─── Scopes ────────────────────────────────────────────────────

    public function scopeAgency(Builder $q, string|ContributionAgency $agency): Builder
    {
        $value = $agency instanceof ContributionAgency ? $agency->value : $agency;
        return $q->where('agency', $value);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
