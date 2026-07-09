<?php

declare(strict_types=1);

namespace App\Common\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * REC-03 — one master-data CSV import.
 */
class ImportBatch extends Model
{
    use HasHashId;

    protected $fillable = [
        'entity_type', 'filename', 'status',
        'total_rows', 'imported_rows', 'errors',
        'created_by', 'rolled_back_at', 'rolled_back_by',
    ];

    protected $casts = [
        'errors'         => 'array',
        'total_rows'     => 'integer',
        'imported_rows'  => 'integer',
        'rolled_back_at' => 'datetime',
    ];

    public function records(): HasMany
    {
        return $this->hasMany(ImportBatchRecord::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
