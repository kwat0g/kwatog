<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBlueprint extends Model
{
    use HasHashId;

    protected $fillable = [
        'product_id', 'title', 'revision', 'notes', 'file_path', 'filename', 'mime', 'size', 'sha256', 'uploaded_by',
    ];

    protected $casts = ['size' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
