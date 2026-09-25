<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\ReturnManagement\Enums\ReturnCaseActorType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Append-only communication and status history for a return case. */
class ReturnCaseEvent extends Model
{
    use HasFactory, HasHashId;

    // Use the application clock just like ReturnCase; a database default may
    // use UTC while the application's persisted timestamps use Manila time.
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $event->metadata = array_merge($event->metadata ?? [], [
                'timestamp_timezone' => config('app.timezone'),
            ]);
        });
    }

    protected $fillable = [
        'action',
        'message',
        'actor_type',
        'actor_name',
        'user_id',
        'customer_portal_user_id',
        'supplier_portal_user_id',
        'metadata',
        'is_public',
    ];

    protected $casts = [
        'actor_type' => ReturnCaseActorType::class,
        'metadata' => 'array',
        'is_public' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customerPortalUser(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalUser::class);
    }

    public function supplierPortalUser(): BelongsTo
    {
        return $this->belongsTo(SupplierPortalUser::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReturnCaseAttachment::class, 'event_id')->orderBy('id');
    }
}
