<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOrderFeedback extends Model
{
    protected $table = 'customer_order_feedback';

    protected $fillable = [
        'sales_order_id',
        'customer_id',
        'customer_portal_user_id',
        'rating',
        'comment',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function portalUser(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalUser::class, 'customer_portal_user_id');
    }
}
