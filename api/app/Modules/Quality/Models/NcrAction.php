<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\NcrActionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sprint 7 — Task 61. Single action row on an NCR. */
class NcrAction extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'ncr_id', 'action_type', 'description', 'performed_by', 'performed_at',
        'due_date', 'owner_id', 'verified_at', 'verified_by',
    ];

    protected $casts = [
        'action_type'  => NcrActionType::class,
        'performed_at' => 'datetime',
        'due_date'     => 'date',
        'verified_at'  => 'datetime',
    ];

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'ncr_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
