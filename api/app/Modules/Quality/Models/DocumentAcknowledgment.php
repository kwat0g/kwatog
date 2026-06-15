<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T3.5 — One row per (revision, user). Created in bulk on publish.
 * Flipped from null → timestamp by the user via the self-service API.
 */
class DocumentAcknowledgment extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'document_revision_id',
        'user_id',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'document_revision_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
