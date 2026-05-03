<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Enums\ShipmentDocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentDocument extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'shipment_id', 'document_type', 'file_path',
        'original_filename', 'file_size_bytes', 'mime_type',
        'notes', 'uploaded_by', 'uploaded_at',
    ];

    protected $casts = [
        'document_type'    => ShipmentDocumentType::class,
        'file_size_bytes'  => 'integer',
        'uploaded_at'      => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
