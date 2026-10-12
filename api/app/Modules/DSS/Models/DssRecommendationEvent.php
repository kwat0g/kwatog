<?php

declare(strict_types=1);

namespace App\Modules\DSS\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DssRecommendationEvent extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'dss_recommendation_events';

    protected $fillable = [
        'recommendation_id',
        'event',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(DssRecommendation::class, 'recommendation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
