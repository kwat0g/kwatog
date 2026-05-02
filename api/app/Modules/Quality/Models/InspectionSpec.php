<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 7 — Task 59. Inspection specification root row.
 *
 * One per product (UNIQUE constraint at the DB level). Updates do not
 * supersede the spec — they bump `version` and overwrite. Sprint 7 keeps
 * spec authoring single-active; full history is queued for Sprint 8 if
 * the audit programme needs versioned spec lineage.
 */
class InspectionSpec extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'product_id', 'version', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'version'   => 'integer',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionSpecItem::class)->orderBy('sort_order');
    }
}
