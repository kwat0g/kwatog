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

    protected static function newFactory(): \Database\Factories\CustomerFactory
    {
        return \Database\Factories\CustomerFactory::new();
    }

    protected $fillable = [
        'name', 'code', 'contact_person', 'email', 'phone', 'address',
        // Chain 1 — where the truck actually goes, which is not the billing
        // address above, and who signs for the goods at that gate.
        'delivery_address', 'delivery_contact_person', 'delivery_contact_phone',
        'allowed_payment_methods', 'shipping_payer', 'agreement_starts_on', 'agreement_ends_on',
        'warranty_duration_days', 'warranty_report_window_days', 'warranty_start_basis',
        'warranty_covered_issues', 'warranty_remedies',
        'tin', 'credit_limit', 'payment_terms_days', 'is_active',
    ];

    protected $casts = [
        'tin'                => 'encrypted',
        'is_active'          => 'boolean',
        'credit_limit'       => 'decimal:2',
        'payment_terms_days' => 'integer',
        'allowed_payment_methods' => 'array',
        'agreement_starts_on' => 'date:Y-m-d',
        'agreement_ends_on' => 'date:Y-m-d',
        'warranty_duration_days' => 'integer',
        'warranty_report_window_days' => 'integer',
        'warranty_covered_issues' => 'array',
        'warranty_remedies' => 'array',
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
