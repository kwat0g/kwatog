<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\OpportunityStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'opportunity_number',
        'lead_id',
        'customer_id',
        'title',
        'stage',
        'probability',
        'estimated_value',
        'expected_close_date',
        'actual_close_date',
        'lost_reason',
        'assigned_to',
        'notes',
    ];

    protected $casts = [
        'stage'               => OpportunityStage::class,
        'probability'         => 'integer',
        'estimated_value'     => 'decimal:2',
        'expected_close_date' => 'date',
        'actual_close_date'   => 'date',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }
}
