<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProductWarrantyOption extends Model
{
    use HasHashId;

    protected $fillable = [
        'customer_id', 'product_id', 'label', 'warranty_duration_days', 'warranty_report_window_days',
        'warranty_start_basis', 'warranty_covered_issues', 'warranty_remedies', 'additional_terms', 'position',
    ];

    protected $casts = [
        'warranty_duration_days' => 'integer',
        'warranty_report_window_days' => 'integer',
        'warranty_covered_issues' => 'array',
        'warranty_remedies' => 'array',
        'position' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, mixed> */
    public function termsSnapshot(): array
    {
        return [
            'warranty_option_id' => $this->hash_id,
            'warranty_option_label' => $this->label,
            'warranty_duration_days' => $this->warranty_duration_days,
            'warranty_report_window_days' => $this->warranty_report_window_days,
            'warranty_start_basis' => $this->warranty_start_basis,
            'warranty_covered_issues' => $this->warranty_covered_issues,
            'warranty_remedies' => $this->warranty_remedies,
            'additional_terms' => $this->additional_terms,
        ];
    }
}
