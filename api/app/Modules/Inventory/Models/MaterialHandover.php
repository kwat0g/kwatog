<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialHandover extends Model
{
    use HasAuditLog, HasHashId;

    public $timestamps = false;

    protected $fillable = ['stock_movement_id', 'received_by_employee_id', 'recipient_name', 'recorded_by', 'quantity', 'handed_over_at'];

    protected $casts = ['quantity' => 'decimal:3', 'handed_over_at' => 'datetime'];

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
