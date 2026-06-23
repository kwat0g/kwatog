<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Quality\Enums\PpapLevel;
use App\Modules\Quality\Enums\PpapStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** IATF 16949 — Production Part Approval Process submission for a vendor+item. */
class PpapSubmission extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'ppap_number', 'vendor_id', 'item_id', 'product_id', 'purchase_order_id',
        'ppap_level', 'submission_date', 'status', 'submission_document_path',
        'rejection_reason', 'submitted_by', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'expires_at', 'revision', 'notes',
    ];

    protected $casts = [
        'ppap_level'      => PpapLevel::class,
        'status'          => PpapStatus::class,
        'submission_date' => 'date',
        'reviewed_at'     => 'datetime',
        'approved_at'     => 'datetime',
        'expires_at'      => 'datetime',
        'revision'        => 'integer',
    ];

    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function elements(): HasMany { return $this->hasMany(PpapElement::class); }

    /** Approved and not past its expiry. */
    public function scopeActiveApproved(Builder $q): Builder
    {
        return $q->where('status', PpapStatus::Approved->value)
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
