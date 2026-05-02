<?php

declare(strict_types=1);

namespace App\Modules\MRP\Models;

use App\Common\Traits\HasHashId;
use App\Modules\MRP\Enums\MoldEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoldHistory extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'mold_history';

    public $timestamps = false;

    protected $fillable = [
        'mold_id', 'event_type', 'description', 'cost',
        'performed_by', 'event_date', 'shot_count_at_event',
    ];

    protected $casts = [
        'event_type'          => MoldEventType::class,
        'event_date'          => 'date',
        'cost'                => 'decimal:2',
        'shot_count_at_event' => 'integer',
        'created_at'          => 'datetime',
    ];

    public function mold(): BelongsTo
    {
        return $this->belongsTo(Mold::class);
    }
}
