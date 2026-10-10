<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionPieceReading extends Model
{
    protected $fillable = ['inspection_id', 'requirement_id', 'piece_number', 'measured_value', 'is_pass', 'recorded_by'];

    protected $casts = ['piece_number' => 'integer', 'measured_value' => 'decimal:4', 'is_pass' => 'boolean'];

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
