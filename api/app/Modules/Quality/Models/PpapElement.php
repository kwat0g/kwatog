<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Quality\Enums\PpapElementStatus;
use App\Modules\Quality\Enums\PpapElementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One checklist element within a PPAP submission. */
class PpapElement extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'ppap_submission_id', 'element_type', 'status', 'document_path', 'notes',
    ];

    protected $casts = [
        'element_type' => PpapElementType::class,
        'status'       => PpapElementStatus::class,
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(PpapSubmission::class, 'ppap_submission_id');
    }
}
