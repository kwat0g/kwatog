<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqDocument extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'request_for_quote_id', 'supplier_quote_id', 'vendor_id', 'uploaded_by_user',
        'uploaded_by_portal_user', 'document_type', 'original_filename', 'mime_type',
        'size_bytes', 'file_path',
    ];

    protected $casts = ['size_bytes' => 'integer'];

    public function rfq(): BelongsTo { return $this->belongsTo(RequestForQuote::class, 'request_for_quote_id'); }
    public function quote(): BelongsTo { return $this->belongsTo(SupplierQuote::class, 'supplier_quote_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by_user'); }
    public function portalUploader(): BelongsTo { return $this->belongsTo(SupplierPortalUser::class, 'uploaded_by_portal_user'); }
}
