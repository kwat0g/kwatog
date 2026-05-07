<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardLayout extends Model
{
    use HasFactory, HasHashId;

    public const OWNER_ROLE = 'role';
    public const OWNER_USER = 'user';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'widget_key',
        'position_x',
        'position_y',
        'width',
        'height',
    ];

    protected $casts = [
        'owner_id'   => 'integer',
        'position_x' => 'integer',
        'position_y' => 'integer',
        'width'      => 'integer',
        'height'     => 'integer',
    ];
}
