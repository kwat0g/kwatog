<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalContractTemplate extends Model
{
    use HasHashId;

    protected $fillable = [
        'name', 'realm', 'file_path', 'filename', 'size', 'pdf_fields', 'field_mappings',
        'required_fields', 'version', 'source_sha256', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'pdf_fields' => 'array',
            'field_mappings' => 'array',
            'required_fields' => 'array',
            'version' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
