<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    use HasFactory, HasHashId;

    protected $fillable = [
        'key',
        'name',
        'description',
        'module',
        'permission',
        'default_w',
        'default_h',
    ];

    protected $casts = [
        'default_w' => 'integer',
        'default_h' => 'integer',
    ];
}
