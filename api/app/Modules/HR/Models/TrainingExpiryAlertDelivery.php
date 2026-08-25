<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingExpiryAlertDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_training_id',
        'alert_level',
        'recipient_user_id',
        'channel',
        'status',
        'attempted_at',
        'delivered_at',
        'error',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function trainingRecord(): BelongsTo
    {
        return $this->belongsTo(EmployeeTraining::class, 'employee_training_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
