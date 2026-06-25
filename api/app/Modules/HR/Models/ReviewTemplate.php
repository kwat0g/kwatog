<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;

class ReviewTemplate extends Model
{
    use HasHashId;

    protected $fillable = ['name', 'description', 'criteria', 'is_active'];

    protected $casts = [
        'criteria'  => 'array',
        'is_active' => 'boolean',
    ];
}
