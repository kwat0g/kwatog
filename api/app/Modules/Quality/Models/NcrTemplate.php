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

/**
 * ADV7 — Reusable NCR template.
 *
 * Pre-fills source, severity, product, and defect description so QC
 * officers can file common NCR types (e.g. "Incoming QC fail — resin
 * contamination") in one click.
 */
class NcrTemplate extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'name', 'source', 'severity',
        'product_id', 'defect_description', 'notes',
        'created_by', 'is_active',
    ];

    protected $casts = [
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
}
