<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProductWarrantyOverride extends Model
{
    use HasHashId;

    protected $fillable = [
        'customer_id', 'product_id', 'warranty_duration_days', 'warranty_report_window_days',
        'warranty_start_basis', 'warranty_covered_issues', 'warranty_remedies',
    ];

    protected $casts = [
        'warranty_duration_days' => 'integer',
        'warranty_report_window_days' => 'integer',
        'warranty_covered_issues' => 'array',
        'warranty_remedies' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
