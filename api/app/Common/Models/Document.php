<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Enums\DocumentType;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Series E (Task E3) — vault row. One per generated PDF/file.
 * Polymorphic: any module's record can attach without schema churn.
 *
 * @property string $hash_id
 */
class Document extends Model
{
    use HasFactory, SoftDeletes, HasHashId;

    protected $fillable = [
        'document_type',
        'entity_type',
        'entity_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'generated_by',
        'generated_at',
        'is_confidential',
        'checksum_sha256',
    ];

    protected $casts = [
        'document_type'    => DocumentType::class,
        'is_confidential'  => 'boolean',
        'generated_at'     => 'datetime',
        'file_size'        => 'integer',
    ];

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Scope: documents for a specific entity (by class + id, or by Model).
     */
    public function scopeForEntity(Builder $query, Model|string $type, ?int $id = null): Builder
    {
        if ($type instanceof Model) {
            return $query
                ->where('entity_type', $type::class)
                ->where('entity_id', $type->getKey());
        }
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }
}
