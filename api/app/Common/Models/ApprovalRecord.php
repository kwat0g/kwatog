<?php

declare(strict_types=1);

namespace App\Common\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRecord extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'approvable_type', 'approvable_id',
        'step_order', 'role_slug',
        'approver_id', 'action', 'remarks', 'acted_at', 'created_at',
    ];
    protected $casts = [
        'acted_at'   => 'datetime',
        'created_at' => 'datetime',
    ];

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Auth\Models\User::class, 'approver_id');
    }
}
