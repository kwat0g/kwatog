<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\ReviewCycleStatus;
use App\Modules\HR\Enums\ReviewCycleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewCycle extends Model
{
    use HasHashId;

    protected $fillable = [
        'name', 'cycle_type', 'start_date', 'end_date', 'description', 'created_by',
    ];

    protected $casts = [
        'cycle_type'  => ReviewCycleType::class,
        'status'      => ReviewCycleStatus::class,
        'start_date'  => 'date',
        'end_date'    => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }
}
