<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverCredential extends Model
{
    use HasHashId;

    protected $fillable = [
        'user_id', 'license_number', 'expires_at', 'document_path',
        'document_name', 'mime_type', 'file_size', 'uploaded_by',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'file_size' => 'integer',
    ];

    protected $hidden = ['document_path'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getStatusAttribute(): string
    {
        return $this->expires_at?->isBefore(today()) ? 'expired' : 'valid';
    }

    public function documentUrl(): ?string
    {
        return $this->document_path
            ? "/api/v1/supply-chain/driver-credentials/{$this->hash_id}/document"
            : null;
    }
}
