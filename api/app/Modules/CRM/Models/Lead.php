<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Common\Traits\HasAuditLog;
use App\Common\Traits\HasHashId;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\LeadSource;
use App\Modules\CRM\Enums\LeadStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    use HasFactory, HasHashId, HasAuditLog;

    protected $fillable = [
        'lead_number',
        'company_name',
        'contact_person',
        'email',
        'phone',
        'source',
        'status',
        'estimated_value',
        'notes',
        'assigned_to',
        'customer_id',
        'converted_to_opportunity_id',
    ];

    protected $casts = [
        'source'          => LeadSource::class,
        'status'          => LeadStatus::class,
        'estimated_value' => 'decimal:2',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'converted_to_opportunity_id');
    }
}
