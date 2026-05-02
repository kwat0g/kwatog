<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int                 $id
 * @property string              $code
 * @property string              $name
 * @property AccountType         $type
 * @property NormalBalance       $normal_balance
 * @property ?int                $parent_id
 * @property bool                $is_active
 * @property ?string             $description
 */
class Account extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'code', 'name', 'type', 'normal_balance', 'parent_id', 'is_active', 'description',
    ];

    protected $casts = [
        'type'            => AccountType::class,
        'normal_balance'  => NormalBalance::class,
        'is_active'       => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOfType(Builder $q, AccountType|string $t): Builder
    {
        return $q->where('type', $t instanceof AccountType ? $t->value : $t);
    }

    public function getIsLeafAttribute(): bool
    {
        return ! $this->children()->exists();
    }

    public function hasPostedLines(): bool
    {
        return $this->journalLines()
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->exists();
    }
}
