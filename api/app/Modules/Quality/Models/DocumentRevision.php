<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * T3.5 — Append-only revision row. One row per published version of a
 * controlled document. `is_current` is service-enforced (exactly one
 * row per document_id is true).
 */
class DocumentRevision extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'document_id',
        'revision_number',
        'effective_date',
        'change_reason',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'published_at',
        'published_by',
        'is_current',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'effective_date'  => 'date',
        'file_size'       => 'integer',
        'published_at'    => 'datetime',
        'is_current'      => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ControlledDocument::class, 'document_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(DocumentAcknowledgment::class, 'document_revision_id');
    }
}
