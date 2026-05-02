<?php

declare(strict_types=1);

namespace App\Modules\Production\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderDefect extends Model
{
    use HasFactory, HasHashId;

    public $timestamps = false;

    protected $fillable = ['output_id', 'defect_type_id', 'count'];

    protected $casts = ['count' => 'integer'];

    public function output(): BelongsTo
    {
        return $this->belongsTo(WorkOrderOutput::class, 'output_id');
    }

    public function defectType(): BelongsTo
    {
        return $this->belongsTo(DefectType::class);
    }
}
