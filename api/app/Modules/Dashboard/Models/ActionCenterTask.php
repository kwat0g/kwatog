<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionCenterTask extends Model
{
    use HasHashId;

    protected $fillable = ['item_key', 'state', 'assigned_to', 'due_at', 'snoozed_until', 'notes', 'updated_by'];

    protected $casts = ['due_at' => 'datetime', 'snoozed_until' => 'datetime'];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ActionCenterTaskEvent::class, 'task_id');
    }
}
