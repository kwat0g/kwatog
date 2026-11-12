<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasHashId;

    protected $fillable = ['product_id', 'file_path', 'filename', 'mime', 'size', 'position', 'alt_text', 'is_primary'];

    protected $casts = ['size' => 'integer', 'position' => 'integer', 'is_primary' => 'boolean'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
