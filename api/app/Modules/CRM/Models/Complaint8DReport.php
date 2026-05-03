<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasHashId;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint8DReport extends Model
{
    use HasFactory, HasHashId;

    protected $table = 'complaint_8d_reports';

    protected $fillable = [
        'complaint_id',
        'd1_team', 'd2_problem', 'd3_containment', 'd4_root_cause',
        'd5_corrective_action', 'd6_verification', 'd7_prevention', 'd8_recognition',
        'finalized_by', 'finalized_at',
    ];

    protected $casts = [
        'finalized_at' => 'datetime',
    ];

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(CustomerComplaint::class, 'complaint_id');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
