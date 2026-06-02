<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADV7 — One row per uploaded proof file (signed DR, photo, customer PO
 * confirmation) attached to a delivery. Multiple proofs per delivery are
 * allowed; at least one must exist before a delivery can be confirmed.
 */
class DeliveryProof extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'delivery_id',
        'proof_type',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
        'notes',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
