<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Common\Traits\HasHashId;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalShippingDocument extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'purchase_order_id',
        'bill_id',
        'document_type',
        'file_path',
        'original_filename',
        'file_size_bytes',
        'mime_type',
        'notes',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'uploaded_at'     => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(SupplierPortalUser::class, 'uploaded_by');
    }
}
