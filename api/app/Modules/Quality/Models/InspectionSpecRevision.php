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

/** Immutable inspection-spec revision metadata and item lineage. */
class InspectionSpecRevision extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'inspection_spec_id', 'version', 'created_by', 'notes',
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    public function spec(): BelongsTo
    {
        return $this->belongsTo(InspectionSpec::class, 'inspection_spec_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionSpecItem::class, 'inspection_spec_revision_id')
            ->withTrashed()
            ->orderBy('sort_order');
    }
}
