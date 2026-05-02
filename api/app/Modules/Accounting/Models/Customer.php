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

class Customer extends Model
{
    use HasFactory, SoftDeletes, HasHashId, HasAuditLog;

    protected $fillable = [
        'name', 'contact_person', 'email', 'phone', 'address',
        'tin', 'credit_limit', 'payment_terms_days', 'is_active',
    ];

    protected $casts = [
        'tin'                => 'encrypted',
        'is_active'          => 'boolean',
        'credit_limit'       => 'decimal:2',
        'payment_terms_days' => 'integer',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
