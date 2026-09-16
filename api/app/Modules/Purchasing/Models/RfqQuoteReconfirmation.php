<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RfqQuoteReconfirmation extends Model
{
    use HasAuditLog, HasFactory, HasHashId;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'purchase_order_id', 'supplier_quote_id', 'supplier_portal_user_id',
        'terms_snapshot', 'reason', 'requested_at', 'confirmed_at',
    ];

    protected $casts = [
        'terms_snapshot' => 'array',
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplierQuote(): BelongsTo
    {
        return $this->belongsTo(SupplierQuote::class);
    }

    public function supplierPortalUser(): BelongsTo
    {
        return $this->belongsTo(SupplierPortalUser::class);
    }
}
