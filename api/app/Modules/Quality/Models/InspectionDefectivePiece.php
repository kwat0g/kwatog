<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionDefectivePiece extends Model
{
    protected $fillable = ['inspection_id', 'piece_number', 'problem_description', 'recorded_by', 'requirement_id'];

    protected $casts = ['piece_number' => 'integer'];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(InspectionMeasurement::class, 'requirement_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
