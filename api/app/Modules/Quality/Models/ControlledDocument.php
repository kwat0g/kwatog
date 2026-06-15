<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * T3.5 — IATF controlled document catalog row.
 *
 * Holds metadata. The actual SOP/spec/form file lives on the
 * `document_revisions` row marked `is_current=true` (one per document).
 *
 * Fields written by services (never mass-assigned from controllers):
 *   - last_reviewed_at      — stamped by DocumentService::markReviewed()
 *   - last_review_alert_at  — stamped by DocumentReviewService::check()
 */
class ControlledDocument extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'code',
        'title',
        'category',
        'description',
        'assignee_role',
        'review_interval_months',
        'is_active',
    ];

    protected $casts = [
        'review_interval_months' => 'integer',
        'last_reviewed_at'       => 'datetime',
        'last_review_alert_at'   => 'datetime',
        'is_active'              => 'boolean',
    ];

    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class, 'document_id');
    }

    public function currentRevision(): HasOne
    {
        return $this->hasOne(DocumentRevision::class, 'document_id')
            ->where('is_current', true);
    }
}
