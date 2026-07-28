<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionCenterTaskEvent extends Model
{
    protected $fillable = ['task_id', 'action', 'from_state', 'to_state', 'metadata', 'acted_by'];

    protected $casts = ['metadata' => 'array'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ActionCenterTask::class, 'task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }
}
