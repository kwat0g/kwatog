<?php

declare(strict_types=1);

namespace App\Modules\DSS\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One open recommendation per (detector, dedupe_key) — enforced by the partial
 * unique index, not by callers. Status transitions go through
 * RecommendationService (W0-3), never mass-assignment: `status` is deliberately
 * absent from $fillable (same hardening as PR/PO/NCR/Loan).
 */
class DssRecommendation extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'dss_recommendations';

    protected $fillable = [
        'detector_id',
        'detector_version',
        'domain',
        'type',
        'subject_type',
        'subject_id',
        'severity',
        'priority_score',
        'impact_estimate',
        'impact_unit',
        'impact_is_estimate',
        'confidence',
        'data_sufficiency',
        'rationale',
        'action',
        'dedupe_key',
        'expires_at',
        'snoozed_until',
        'owner_permissions',
        'owner_role_slugs',
        'decision_code',
        'policy_version',
        'source_references',
        'verification_context',
        'first_detected_at',
        'last_evaluated_at',
        'last_evaluated_run_id',
        'source_observed_at',
        'business_due_at',
        'resolution_evidence',
        'verification_status',
        'predecessor_id',
        'impact_currency',
        'impact_metric',
    ];

    protected $casts = [
        'priority_score' => 'decimal:4',
        'impact_estimate' => 'decimal:2',
        'impact_is_estimate' => 'boolean',
        'confidence' => 'decimal:4',
        'rationale' => 'array',
        'action' => 'array',
        'owner_permissions' => 'array',
        'owner_role_slugs' => 'array',
        'source_references' => 'array',
        'verification_context' => 'array',
        'resolution_evidence' => 'array',
        'expires_at' => 'datetime',
        'decided_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'first_detected_at' => 'datetime',
        'last_evaluated_at' => 'datetime',
        'source_observed_at' => 'datetime',
        'business_due_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DssRecommendationEvent::class, 'recommendation_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeForDetector($query, string $detectorId)
    {
        return $query->where('detector_id', $detectorId);
    }
}
