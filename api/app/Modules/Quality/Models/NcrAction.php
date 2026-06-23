<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\EffectivenessStatus;
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
        // CAPA effectiveness loop.
        'effectiveness_status', 'effectiveness_checked_at', 'effectiveness_notes',
        'effectiveness_check_count', 'next_effectiveness_check_at',
    ];

    protected $casts = [
        'action_type'  => NcrActionType::class,
        'performed_at' => 'datetime',
        'due_date'     => 'date',
        'verified_at'  => 'datetime',
        // CAPA effectiveness loop.
        'effectiveness_status'        => EffectivenessStatus::class,
        'effectiveness_checked_at'    => 'datetime',
        'effectiveness_check_count'   => 'integer',
        'next_effectiveness_check_at' => 'date',
    ];

    public function ncr(): BelongsTo
    {
        return $this->belongsTo(NonConformanceReport::class, 'ncr_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
