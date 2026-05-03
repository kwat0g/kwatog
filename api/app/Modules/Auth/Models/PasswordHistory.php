<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Common\Traits\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PasswordHistory extends Model
{
    use HasHashId;

    public $timestamps = false;
    protected $table = 'password_history';
    protected $fillable = ['user_id', 'password_hash', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
