<?php

declare(strict_types=1);

namespace App\Modules\B2B\Models;

use Illuminate\Database\Eloquent\Model;

class PortalPasswordHistory extends Model
{
    public $timestamps = false;

    protected $table = 'portal_password_history';

    protected $fillable = [
        'portal_type',
        'portal_user_id',
        'password_hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
