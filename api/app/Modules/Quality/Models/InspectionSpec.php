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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sprint 7 — Task 59. Inspection specification root row.
 *
 * One root per product (UNIQUE constraint at the DB level). Each save creates
 * an immutable revision and appends new item rows; the root carries the
 * current version and active/archive state.
 */
class InspectionSpec extends Model
{
    use HasFactory, HasHashId, HasAuditLog, SoftDeletes;

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

    public function revisions(): HasMany
    {
        return $this->hasMany(InspectionSpecRevision::class)->orderBy('version');
    }

    public function currentRevision(): HasOne
    {
        return $this->hasOne(InspectionSpecRevision::class)->ofMany('version', 'max');
    }

    /**
     * Compatibility bridge for legacy model writers that create an item or
     * inspection directly instead of going through the revisioning service.
     * New HTTP writes always create the revision explicitly in the service.
     */
    public function ensureCurrentRevision(): InspectionSpecRevision
    {
        return $this->revisions()->firstOrCreate(
            ['version' => (int) $this->version],
            [
                'created_by' => (int) $this->created_by,
                'notes' => $this->notes,
            ],
        );
    }
}
