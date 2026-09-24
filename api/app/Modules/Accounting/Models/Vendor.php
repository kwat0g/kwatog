<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected static function newFactory(): \Database\Factories\VendorFactory
    {
        return \Database\Factories\VendorFactory::new();
    }

    protected $fillable = [
        'name', 'contact_person', 'email', 'phone', 'address',
        'tin', 'payment_terms_days', 'withholding_tax_type', 'is_active', 'created_by',
    ];

    protected $hidden = ['tin_hash'];

    protected $casts = [
        'tin'                   => 'encrypted',
        'is_active'             => 'boolean',
        'payment_terms_days'    => 'integer',
        'withholding_tax_type'  => \App\Modules\Accounting\Enums\WithholdingTaxType::class,
    ];

    protected static function booted(): void
    {
        // tin is encrypted with a random IV, so it cannot carry a unique index;
        // tin_hash is the deterministic blind index that can.
        static::saving(function (self $vendor) {
            // Compare normalized hashes, not ciphertext: re-saving a legacy
            // duplicate (left unindexed by 0557) with its TIN merely
            // reformatted must not claim the hash and hit the unique index.
            if ($vendor->isDirty('tin')
                && self::tinHash($vendor->tin) !== self::tinHash($vendor->getOriginal('tin'))) {
                $vendor->tin_hash = self::tinHash($vendor->tin);
            }
        });
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && ! $this->trashed();
    }

    /**
     * Compute TIN hash for duplicate detection.
     * Normalizes by stripping everything except digits, then hashes with app key.
     * Returns null if TIN is null or normalizes to empty.
     */
    public static function tinHash(?string $tin): ?string
    {
        if ($tin === null || $tin === '') {
            return null;
        }

        // Strip everything except digits
        $normalized = preg_replace('/[^0-9]/', '', $tin);

        if ($normalized === '') {
            return null;
        }

        return hash_hmac('sha256', $normalized, config('app.key'));
    }
}
