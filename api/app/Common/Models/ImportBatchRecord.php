<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * REC-03 — a single model created by an import batch (for rollback).
 */
class ImportBatchRecord extends Model
{
    protected $fillable = ['import_batch_id', 'recordable_type', 'recordable_id'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function recordable(): MorphTo
    {
        return $this->morphTo();
    }
}
