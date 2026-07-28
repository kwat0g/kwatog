<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseScanEvent extends Model
{
    protected $fillable = ['user_id', 'barcode', 'result_type', 'is_recognized', 'context'];

    protected $casts = ['is_recognized' => 'boolean', 'context' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
