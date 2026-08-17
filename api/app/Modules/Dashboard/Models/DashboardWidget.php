<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Dashboard\Enums\RenderKind;
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
        'render_kind',
        'link_path',
        'default_w',
        'default_h',
    ];

    protected $casts = [
        'render_kind' => RenderKind::class,
        'default_w' => 'integer',
        'default_h' => 'integer',
    ];
}
